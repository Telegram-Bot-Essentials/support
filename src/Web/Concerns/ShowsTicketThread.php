<?php

namespace TelegramBotEssentials\Support\Web\Concerns;

use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketMessage;
use TelegramBotEssentials\Support\Services\TicketFiles;
use TelegramBotEssentials\Support\Services\TicketHistory;

/** What the customer's and the staff's ticket screens share, so they cannot drift apart. */
trait ShowsTicketThread
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    abstract protected function ticket(): Ticket;

    abstract protected function viewer(): TicketSide;

    abstract protected function sendReply(Ticket $ticket, TicketContent $content): void;

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

        try {
            $this->sendReply($this->ticket(), new TicketContent(TicketContent::KIND_TEXT, is_string($text) ? $text : ''));
        } catch (FeatureIsDisabled $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

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
        return TicketHistory::author($message, $this->viewer());
    }

    public function isMine(TicketMessage $message): bool
    {
        return $message->side === $this->viewer();
    }
}
