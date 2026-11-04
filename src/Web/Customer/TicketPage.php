<?php

namespace TelegramBotEssentials\Support\Web\Customer;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use TelegramBotEssentials\Essence\Web\Filament\PanelPage;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Web\Concerns\ShowsTicketThread;

/**
 * One of the customer's own tickets. Anyone else's is not found.
 *
 * @property-read Schema $form
 */
class TicketPage extends PanelPage implements IgnoresChannelLock, RefusesSuspendedUsers
{
    use ShowsTicketThread;

    protected string $view = 'tbe-support::web.thread';

    protected static ?string $slug = 'support/{ticket}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $ticketId;

    public function mount(int $ticket): void
    {
        $this->ticketId = $ticket;
        $this->ticket();
        $this->form->fill();
    }

    protected function ticket(): Ticket
    {
        return Ticket::query()->where('bot_user_id', wHook()->user()->getKey())->findOrFail($this->ticketId);
    }

    protected function viewer(): TicketSide
    {
        return TicketSide::Customer;
    }

    protected function sendReply(Ticket $ticket, TicketContent $content): void
    {
        app(Tickets::class)->replyAsCustomer($ticket, wHook()->user(), $content);
    }

    public function getTitle(): string
    {
        return (string) __('tbe-support::web.ticket_title', ['id' => $this->ticketId, 'status' => $this->ticket()->status->label()]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label((string) __('tbe-support::web.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(TicketsPage::getUrl()),
            Action::make('close')
                ->label((string) __('tbe-support::web.close'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->ticket()->status !== TicketStatus::Closed)
                ->action(fn (Tickets $tickets) => $tickets->closeAsCustomer($this->ticket())),
        ];
    }
}
