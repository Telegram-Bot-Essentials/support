<?php

namespace TelegramBotEssentials\Support\Web\Customer;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Web\Filament\PanelPage;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\SupportMode;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\Tickets;
use TelegramBotEssentials\Support\Web\TicketStatusColor;

/** The customer's tickets and the shown contact handles. */
class TicketsPage extends PanelPage implements HasTable, IgnoresChannelLock, RefusesSuspendedUsers
{
    use InteractsWithTable;

    protected string $view = 'tbe-support::web.customer-tickets';

    protected static ?string $slug = 'support';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    public static function getNavigationLabel(): string
    {
        return (string) __('tbe-support::web.nav_support');
    }

    public function getTitle(): string
    {
        return (string) __('tbe-support::web.nav_support');
    }

    public function handles(): ?string
    {
        $handles = settings()->get('support.handles');

        return SupportMode::current()->showsHandles() && is_string($handles) && trim($handles) !== '' ? $handles : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Ticket::query()->where('bot_user_id', wHook()->user()->getKey()))
            ->columns([
                TextColumn::make('id')->label((string) __('tbe-support::web.ticket'))->prefix('#'),
                TextColumn::make('status')
                    ->label((string) __('tbe-support::web.status'))
                    ->badge()
                    ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                    ->color(TicketStatusColor::of(...)),
                TextColumn::make('last_message_at')->label((string) __('tbe-support::web.last_message'))->since(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->recordUrl(fn (Ticket $record): string => TicketPage::getUrl(['ticket' => $record->id]))
            ->emptyStateHeading((string) __('tbe-support::support.tickets.empty'));
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTicket')
                ->label((string) __('tbe-support::web.new_ticket'))
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => SupportMode::current()->allowsTickets())
                ->schema([
                    Textarea::make('message')
                        ->label((string) __('tbe-support::web.message'))
                        ->required()
                        ->maxLength(4000)
                        ->rows(4),
                ])
                ->action(function (array $data, Tickets $tickets) {
                    $message = $data['message'] ?? null;

                    try {
                        $ticket = $tickets->open(wHook()->user(), new TicketContent(TicketContent::KIND_TEXT, is_string($message) ? $message : ''));
                    } catch (FeatureIsDisabled $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return null;
                    }

                    return redirect(TicketPage::getUrl(['ticket' => $ticket->id]));
                }),
        ];
    }
}
