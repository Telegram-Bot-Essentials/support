<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
                    ->requiredWithout('attachment')
                    ->maxLength(4000)
                    ->rows(3),
                // Kept out of storage: it goes to Telegram, which keeps it for the ticket.
                FileUpload::make('attachment')
                    ->label((string) __('tbe-support::web.attach'))
                    ->helperText((string) __('tbe-support::web.attach_hint', ['mb' => intdiv(TicketFiles::MAX_UPLOAD_KB, 1024)]))
                    ->storeFiles(false)
                    ->maxSize(TicketFiles::MAX_UPLOAD_KB),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state = $this->form->getState();
        $text = is_string($state['reply'] ?? null) ? $state['reply'] : '';
        $file = $this->uploadedFile($state['attachment'] ?? null);

        try {
            $content = $file === null
                ? new TicketContent(TicketContent::KIND_TEXT, $text)
                : app(TicketFiles::class)->upload(wHook()->user(), (string) $file->get(), $file->getClientOriginalName(), $file->getMimeType(), $text);

            // Panel in the viewer's language, the customer's copy in the bot's.
            $delivered = inBotLocale(fn () => app(Tickets::class)->replyAsStaff($this->ticket(), wHook()->user(), $content));
        } catch (FeatureIsDisabled $e) {
            // Nothing recorded: the form keeps what was typed, to try again.
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $file?->delete();

        // Saved either way; the staff must know when the customer did not get it.
        $delivered
            ? Notification::make()->title((string) __('tbe-support::web.sent'))->success()->send()
            : Notification::make()->title((string) __('tbe-support::support.answers.not_delivered'))->warning()->send();

        $this->form->fill();
    }

    private function uploadedFile(mixed $state): ?TemporaryUploadedFile
    {
        $files = is_array($state) ? array_values($state) : [$state];

        return ($files[0] ?? null) instanceof TemporaryUploadedFile ? $files[0] : null;
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
                ->action(fn (Tickets $tickets) => inBotLocale(fn () => $tickets->closeAsStaff($this->ticket()))),
        ];
    }
}
