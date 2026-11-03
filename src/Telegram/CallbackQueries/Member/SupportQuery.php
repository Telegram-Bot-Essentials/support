<?php

namespace TelegramBotEssentials\Support\Telegram\CallbackQueries\Member;

use TelegramBotEssentials\Essence\Telegram\CallbackQueries\CallbackQuery;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Services\Relay;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Telegram\Features\Member\SupportFeature;
use TelegramBotEssentials\Support\Telegram\StateAnswers\Member\SupportAnswer;

class SupportQuery extends CallbackQuery implements IgnoresChannelLock, RefusesSuspendedUsers
{
    protected string $type = SupportFeature::TYPE;

    public function start(): void
    {
        SupportFeature::main()->update();
    }

    public function tickets(int $page = 1): void
    {
        SupportFeature::tickets($page)->update();
    }

    public function show(Ticket $ticket, int $page = 1): void
    {
        $this->ensureOwn($ticket);

        SupportFeature::show($ticket, $page)->update();
    }

    /** From an alert, which may be a photo and stays readable: a new message, not an edit. */
    public function open(Ticket $ticket): void
    {
        $this->ensureOwn($ticket);

        SupportFeature::show($ticket)->send();
        $this->answer();
    }

    public function newTicket(Tickets $tickets): void
    {
        $tickets->ensureCanOpen(wHook()->user());

        wHook()->conversation()->changeState(encodeAnswerState($this->type, SupportAnswer::METHOD_NEW));
        SupportFeature::prompt('tbe-support::support.prompts.new_ticket')->send();
        $this->answer();
    }

    public function reply(Ticket $ticket): void
    {
        $this->ensureOwn($ticket);

        wHook()->conversation()->changeState(encodeAnswerState($this->type, SupportAnswer::METHOD_REPLY, ['ticket' => $ticket->id]));
        SupportFeature::prompt('tbe-support::support.prompts.reply')->send();
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
        $this->ensureOwn($ticket);

        $relay->resendAttachments($ticket, People::peerId(wHook()->user()));
        $this->answer();
    }

    public function close(Ticket $ticket, Tickets $tickets, int $page = 1): void
    {
        $this->ensureOwn($ticket);

        $tickets->closeAsCustomer($ticket);

        SupportFeature::show($ticket->refresh(), $page)->update();
    }

    /** A button's data comes from the client, so whose ticket it names is checked every time. */
    private function ensureOwn(Ticket $ticket): void
    {
        dependsOn($ticket->customer->is(wHook()->user()));
    }
}
