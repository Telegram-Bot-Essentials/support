<?php

namespace TelegramBotEssentials\Support\Telegram\StateAnswers\Admin;

use Telegram\Bot\Objects\Message;
use TelegramBotEssentials\Essence\Enums\AllowableFields;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Telegram\StateAnswers\StateAnswer;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Telegram\Features\Admin\TicketsFeature;

class TicketsAnswer extends StateAnswer
{
    public const METHOD_REPLY = 'reply';

    protected string $type = TicketsFeature::TYPE;

    protected ?string $ability = 'support.manage';

    /** @var array<int, string> */
    protected array $allowedFields = [
        AllowableFields::TEXT->value,
        AllowableFields::PHOTO->value,
        AllowableFields::DOCUMENT->value,
    ];

    public function reply(Ticket $ticket, Tickets $tickets): void
    {
        $message = wHook()->update()->getMessage();
        $content = $message instanceof Message ? TicketContent::fromMessage($message) : null;
        $content ?? throw new FeatureIsDisabled((string) __('tbe-support::support.errors.unsupported'));

        $delivered = $tickets->replyAsStaff($ticket, wHook()->user(), $content);

        wHook()->conversation()->changeState();
        wHook()->api()->sendMessage([
            ...wHook()->replyTo(),
            'text' => (string) __($delivered ? 'tbe-support::support.answers.sent' : 'tbe-support::support.answers.not_delivered'),
            'reply_markup' => replyKeyboard(),
        ]);
    }
}
