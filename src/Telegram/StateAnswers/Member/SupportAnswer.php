<?php

namespace TelegramBotEssentials\Support\Telegram\StateAnswers\Member;

use Telegram\Bot\Objects\Message;
use TelegramBotEssentials\Essence\Enums\AllowableFields;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Telegram\StateAnswers\StateAnswer;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Telegram\Features\Member\SupportFeature;

class SupportAnswer extends StateAnswer implements IgnoresChannelLock, RefusesSuspendedUsers
{
    public const METHOD_NEW = 'newTicket';

    public const METHOD_REPLY = 'reply';

    protected string $type = SupportFeature::TYPE;

    /** @var array<int, string> */
    protected array $allowedFields = [
        AllowableFields::TEXT->value,
        AllowableFields::PHOTO->value,
        AllowableFields::DOCUMENT->value,
    ];

    public function newTicket(Tickets $tickets): void
    {
        $ticket = $tickets->open(wHook()->user(), $this->content());

        $this->finish((string) __('tbe-support::support.answers.ticket_created', ['id' => $ticket->id]));
    }

    public function reply(Ticket $ticket, Tickets $tickets): void
    {
        dependsOn($ticket->customer->is(wHook()->user()));

        $tickets->replyAsCustomer($ticket, wHook()->user(), $this->content());

        $this->finish((string) __('tbe-support::support.answers.sent'));
    }

    private function content(): TicketContent
    {
        $message = wHook()->update()->getMessage();
        $content = $message instanceof Message ? TicketContent::fromMessage($message) : null;

        return $content ?? throw new FeatureIsDisabled((string) __('tbe-support::support.errors.unsupported'));
    }

    private function finish(string $text): void
    {
        wHook()->conversation()->changeState();
        wHook()->api()->sendMessage([
            ...wHook()->replyTo(),
            'text' => $text,
            'reply_markup' => replyKeyboard(),
        ]);
    }
}
