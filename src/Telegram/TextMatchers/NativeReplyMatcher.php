<?php

namespace TelegramBotEssentials\Support\Telegram\TextMatchers;

use Telegram\Bot\Objects\Message;
use TelegramBotEssentials\Essence\Telegram\TextMatchers\TextMatcher;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Models\TicketLink;
use TelegramBotEssentials\Support\Services\Tickets;

/**
 * Replying to a message about a ticket answers that ticket. Both sides use it,
 * so it names no ability: what the sender may do is decided by which side the
 * message they replied to was sent to. Reply keys and open forms go first.
 */
class NativeReplyMatcher extends TextMatcher implements IgnoresChannelLock, RefusesSuspendedUsers
{
    public function matches(Message $message): bool
    {
        // Every unclaimed message passes through here: no query without a reply.
        return $message->has('reply_to_message') && $this->link($message) !== null;
    }

    public function handle(): void
    {
        $message = wHook()->update()->getMessage();

        if (! $message instanceof Message) {
            return;
        }

        $link = $this->link($message);
        $sender = wHook()->user();

        if ($link === null) {
            return;
        }

        $ticket = $link->ticket;

        if ($link->side === TicketSide::Staff) {
            // They may have lost the ability since this alert was sent.
            dependsOn(hasAccess('support.manage'));
        } else {
            dependsOn($ticket->customer->is($sender));
        }

        $content = TicketContent::fromMessage($message);
        dependsOn($content !== null, (string) __('tbe-support::support.errors.unsupported'));
        assert($content instanceof TicketContent);

        if ($link->side === TicketSide::Staff) {
            $answer = app(Tickets::class)->replyAsStaff($ticket, $sender, $content) ? 'sent' : 'not_delivered';
        } else {
            app(Tickets::class)->replyAsCustomer($ticket, $sender, $content);
            $answer = 'sent';
        }

        wHook()->api()->sendMessage([
            ...wHook()->replyTo(),
            'text' => (string) __('tbe-support::support.answers.'.$answer),
            'reply_markup' => replyKeyboard(),
        ]);
    }

    private function link(Message $message): ?TicketLink
    {
        $data = $message->toArray();
        $replied = $data['reply_to_message'] ?? null;
        $chat = is_array($data['chat'] ?? null) ? ($data['chat']['id'] ?? null) : null;

        if (! is_array($replied) || ! isset($replied['message_id']) || ! is_numeric($chat)) {
            return null;
        }

        return TicketLink::query()
            ->where('bot_id', wHook()->bot()->getKey())
            ->where('chat_id', (int) $chat)
            ->where('message_id', $replied['message_id'])
            ->first();
    }
}
