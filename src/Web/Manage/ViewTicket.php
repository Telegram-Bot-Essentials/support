<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Web\Filament\Concerns\GuardsResourcePage;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketMessage;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Services\TicketContext;
use TelegramBotEssentials\Support\Services\TicketFiles;
use TelegramBotEssentials\Support\Services\TicketHistory;
use TelegramBotEssentials\Support\Services\Tickets;

/** @property-read Schema $form */
class ViewTicket extends Page
{
    use GuardsResourcePage;
    use InteractsWithRecord;

    protected static string $resource = TicketResource::class;

    protected string $view = 'tbe-support::web.thread';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('reply')
                    ->label((string) __('tbe-support::web.reply'))
                    ->required()
                    ->maxLength(4000)
                    ->rows(3),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state = $this->form->getState();
        $text = $state['reply'] ?? null;
        $content = new TicketContent(TicketContent::KIND_TEXT, is_string($text) ? $text : '');

        try {
            $delivered = app(Tickets::class)->replyAsStaff($this->ticket(), wHook()->user(), $content);
        } catch (FeatureIsDisabled $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        // Saved either way; the staff must know when the customer did not get it.
        $delivered
            ? Notification::make()->title((string) __('tbe-support::web.sent'))->success()->send()
            : Notification::make()->title((string) __('tbe-support::support.answers.not_delivered'))->warning()->send();

        $this->form->fill();
    }

    public function downloadAttachment(int $messageId): ?StreamedResponse
    {
        // Through the ticket: another ticket's message is not found here.
        $message = $this->ticket()->messages()->whereNotNull('file_id')->findOrFail($messageId);

        try {
            return app(TicketFiles::class)->download($message);
        } catch (FeatureIsDisabled $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    /** @return Collection<int, TicketMessage> */
    public function threadMessages(): Collection
    {
        return $this->ticket()->messages()->with('sender.telegramUser')->oldest('id')->get();
    }

    public function authorOf(TicketMessage $message): string
    {
        return TicketHistory::author($message, TicketSide::Staff);
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
