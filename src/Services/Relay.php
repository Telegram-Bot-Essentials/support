<?php

namespace TelegramBotEssentials\Support\Services;

use Illuminate\Support\Collection;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\Message;
use TelegramBotEssentials\Essence\Contracts\RecipientGroups;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketLink;
use TelegramBotEssentials\Support\Models\TicketMessage;
use TelegramBotEssentials\Support\Telegram\Features\Admin\TicketsFeature;
use TelegramBotEssentials\Support\Telegram\Features\Member\SupportFeature;
use Throwable;

/**
 * Carries a ticket's messages between the customer and the staff. Nothing here
 * throws: a message is saved before it is sent, and a failed send is the
 * caller's to report, never a reason to lose the flow it happened in.
 */
class Relay
{
    public const TARGET_OWNER = 'owner';

    /**
     * Telegram's limits are 4096 and 1024, counted after it parses the HTML:
     * these leave room for the header, and escaping does not eat into them.
     */
    private const TEXT_LIMIT = 3800;

    private const CAPTION_LIMIT = 800;

    /** @return array<string, string> setting options: the owner, then each group the bot has */
    public function targets(): array
    {
        $groups = wHook()->hasBot() ? app(RecipientGroups::class)->options(wHook()->bot()) : [];

        return [self::TARGET_OWNER => (string) __('tbe-support::support.settings.alert_targets_owner')] + $groups;
    }

    /** Whether the customer got it. */
    public function toCustomer(Ticket $ticket, TicketMessage $message): bool
    {
        $customer = $ticket->customer;

        return $this->sendToUser($customer, $ticket, TicketSide::Customer, $message, SupportFeature::alertHeader($ticket), SupportFeature::alertKeys($ticket));
    }

    /**
     * Tells whoever the owner chose. When that reaches nobody it falls back to
     * the owner, so a customer is never left writing to no one.
     */
    public function toStaff(Ticket $ticket, TicketMessage $message, bool $isNew): void
    {
        $header = TicketsFeature::alertHeader($ticket, $isNew);
        $keys = TicketsFeature::alertKeys($ticket);

        $targets = settings()->get('support.alert_targets');
        $targets = is_array($targets) ? array_values(array_map(strval(...), array_filter($targets, is_scalar(...)))) : [];

        $members = $this->groupMembers($ticket->bot, $targets);
        $reached = $members->filter(fn (BotUser $member) => $this->sendToUser($member, $ticket, TicketSide::Staff, $message, $header, $keys));

        if ($reached->isEmpty() || in_array(self::TARGET_OWNER, $targets, true)) {
            $this->tellOwner($ticket, $message, $header, $keys, $reached);
        }
    }

    /** Sends one person the files of a ticket, for going back to them later. */
    public function resendAttachments(Ticket $ticket, int $chatId): int
    {
        $sent = 0;

        $ticket->messages()->whereNotNull('file_id')->latest('id')->limit(10)->get()->reverse()
            ->each(function (TicketMessage $message) use ($chatId, &$sent) {
                if (isolated('support: resend attachment', fn () => $this->sendFile($chatId, $message, (string) $message->created_at?->format('Y-m-d H:i')))) {
                    $sent++;
                }
            });

        return $sent;
    }

    /** Sends a plain notice to the customer, with nothing to link it to. */
    public function notifyCustomer(Ticket $ticket, string $text): bool
    {
        return notifyUser($ticket->customer, $text, ['parse_mode' => 'HTML']);
    }

    /**
     * @param  list<string>  $targets
     * @return Collection<int, BotUser>
     */
    private function groupMembers(Bot $bot, array $targets): Collection
    {
        $groupIds = array_values(array_diff($targets, [self::TARGET_OWNER]));

        if ($groupIds === []) {
            /** @var Collection<int, BotUser> */
            return new Collection;
        }

        return app(RecipientGroups::class)->members($bot, $groupIds);
    }

    /**
     * @param  Keyboard<array-key, mixed>  $keys
     * @param  Collection<int, BotUser>  $alreadyTold
     */
    private function tellOwner(Ticket $ticket, TicketMessage $message, string $header, Keyboard $keys, Collection $alreadyTold): void
    {
        $peerId = $ticket->bot->getAttribute('bot_owner_peer_id');

        // An owner may be in a targeted group too: one message, not two.
        if (! is_numeric($peerId) || $alreadyTold->contains(fn (BotUser $user) => People::peerId($user) === (int) $peerId)) {
            return;
        }

        isolated('support: alert owner', fn () => $this->send((int) $peerId, $ticket, TicketSide::Staff, $message, $header, $keys));
    }

    /** @param  Keyboard<array-key, mixed>  $keys */
    private function sendToUser(BotUser $user, Ticket $ticket, TicketSide $side, TicketMessage $message, string $header, Keyboard $keys): bool
    {
        return isolated('support: relay message', function () use ($user, $ticket, $side, $message, $header, $keys) {
            try {
                $this->send(People::peerId($user), $ticket, $side, $message, $header, $keys);
            } catch (Throwable $e) {
                botUserStatus()->reportFailure($user, $e);

                throw $e;
            }

            botUserStatus()->reportSuccess($user);
        }, for: $user);
    }

    /**
     * Long text is split rather than cut, since this may be the only copy
     * someone reads. The buttons go on the last part, and any part can be
     * replied to.
     *
     * @param  Keyboard<array-key, mixed>  $keys
     */
    private function send(int $chatId, Ticket $ticket, TicketSide $side, TicketMessage $message, string $header, Keyboard $keys): void
    {
        $body = (string) $message->text;

        if ($message->kind !== TicketContent::KIND_TEXT) {
            $fits = mb_strlen($body) <= self::CAPTION_LIMIT;
            $caption = $fits && $body !== '' ? $header."\n\n".e($body) : $header;
            $sent = $this->sendFile($chatId, $message, $caption, $fits ? $keys : null);
            $this->link($ticket, $chatId, $sent, $side);

            if ($fits) {
                return;
            }
        }

        $parts = mb_str_split($body, self::TEXT_LIMIT);
        $lastIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $withHeader = $index === 0 && $message->kind === TicketContent::KIND_TEXT;

            $sent = wHook()->api()->sendMessage(array_filter([
                'chat_id' => $chatId,
                'text' => ($withHeader ? $header."\n\n" : '').e($part),
                'parse_mode' => 'HTML',
                'reply_markup' => $index === $lastIndex ? $keys : null,
            ]));

            $this->link($ticket, $chatId, $sent, $side);
        }
    }

    /** @param  Keyboard<array-key, mixed>|null  $keys */
    private function sendFile(int $chatId, TicketMessage $message, string $caption, ?Keyboard $keys = null): Message
    {
        $params = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys,
        ]);

        return $message->kind === TicketContent::KIND_PHOTO
            ? wHook()->api()->sendPhoto([...$params, 'photo' => (string) $message->file_id])
            : wHook()->api()->sendDocument([...$params, 'document' => (string) $message->file_id]);
    }

    private function link(Ticket $ticket, int $chatId, Message $sent, TicketSide $side): void
    {
        TicketLink::query()->create([
            'ticket_id' => $ticket->id,
            'bot_id' => $ticket->bot_id,
            'chat_id' => $chatId,
            'message_id' => $sent->messageId,
            'side' => $side->value,
        ]);
    }
}
