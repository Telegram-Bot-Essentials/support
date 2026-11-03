<?php

namespace TelegramBotEssentials\Support\Telegram\CallbackQueries\Admin;

use TelegramBotEssentials\Essence\Telegram\CallbackQueries\CallbackQuery;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Services\Relay;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Telegram\Features\Admin\TicketsFeature;
use TelegramBotEssentials\Support\Telegram\StateAnswers\Admin\TicketsAnswer;

class TicketsQuery extends CallbackQuery
{
    protected string $type = TicketsFeature::TYPE;

    protected ?string $ability = 'support.manage';

    public function start(string $tab = 'open', int $page = 1): void
    {
        TicketsFeature::menu($tab, $page)->update();
    }

    public function show(Ticket $ticket, string $tab = 'open', int $page = 1): void
    {
        TicketsFeature::show($ticket, $tab, $page)->update();
    }

    /** From an alert, which may be a photo and stays readable: a new message, not an edit. */
    public function open(Ticket $ticket): void
    {
        TicketsFeature::show($ticket)->send();
        $this->answer();
    }

    public function reply(Ticket $ticket): void
    {
        wHook()->conversation()->changeState(encodeAnswerState($this->type, TicketsAnswer::METHOD_REPLY, ['ticket' => $ticket->id]));
        TicketsFeature::prompt($ticket->id)->send();
        $this->answer();
    }

    public function cancelPrompt(): void
    {
        wHook()->conversation()->changeState();
        $this->answer((string) __('tbe-support::support.answers.cancelled'));
        wHook()->api()->deleteMessage([
            'chat_id' => People::peerId(wHook()->user()),
            'message_id' => wHook()->update()->callbackQuery?->message?->messageId,
        ]);
    }

    public function attachments(Ticket $ticket, Relay $relay): void
    {
        $relay->resendAttachments($ticket, People::peerId(wHook()->user()));
        $this->answer();
    }

    public function close(Ticket $ticket, Tickets $tickets, string $tab = 'open', int $page = 1): void
    {
        $tickets->closeAsStaff($ticket);

        TicketsFeature::show($ticket->refresh(), $tab, $page)->update();
    }
}
