<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use TelegramBotEssentials\Essence\Web\Filament\Concerns\GuardsResourcePage;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Services\TicketContext;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Web\Concerns\ShowsTicketThread;

/** @property-read Schema $form */
class ViewTicket extends Page
{
    use GuardsResourcePage;
    use InteractsWithRecord;
    use ShowsTicketThread;

    protected static string $resource = TicketResource::class;

    protected string $view = 'tbe-support::web.thread';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->form->fill();
    }

    protected function ticket(): Ticket
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        return $ticket->refresh();
    }

    protected function viewer(): TicketSide
    {
        return TicketSide::Staff;
    }

    protected function sendReply(Ticket $ticket, TicketContent $content): void
    {
        $delivered = app(Tickets::class)->replyAsStaff($ticket, wHook()->user(), $content);

        // Saved either way; the staff must know when the customer did not get it.
        $delivered
            ? Notification::make()->title((string) __('tbe-support::web.sent'))->success()->send()
            : Notification::make()->title((string) __('tbe-support::support.answers.not_delivered'))->warning()->send();
    }

    public function getTitle(): string
    {
        return (string) __('tbe-support::web.ticket_title', ['id' => $this->ticket()->id, 'status' => $this->ticket()->status->label()]);
    }

    public function customerName(): string
    {
        return People::fullName($this->ticket()->customer);
    }

    public function customerUsername(): ?string
    {
        return People::username($this->ticket()->customer);
    }

    public function customerPeerId(): int
    {
        return People::peerId($this->ticket()->customer);
    }

    /** What the app adds, such as the customer's services. The blocks return HTML. */
    public function contextHtml(): string
    {
        return app(TicketContext::class)->render($this->ticket());
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label((string) __('tbe-support::web.close'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->ticket()->status !== TicketStatus::Closed)
                ->action(fn (Tickets $tickets) => $tickets->closeAsStaff($this->ticket())),
        ];
    }
}
