<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TelegramBotEssentials\Essence\Web\Filament\PanelDates;
use TelegramBotEssentials\Essence\Web\Filament\PanelGroup;
use TelegramBotEssentials\Essence\Web\Filament\PanelResource;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Web\TicketStatusColor;
use UnitEnum;

class TicketResource extends PanelResource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $ability = 'support.manage';

    protected static ?string $slug = 'tickets';

    protected static ?string $tenantOwnershipRelationshipName = 'bot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = PanelGroup::Support;

    public static function getNavigationLabel(): string
    {
        return (string) __('tbe-support::web.nav_tickets');
    }

    public static function getModelLabel(): string
    {
        return (string) __('tbe-support::web.ticket');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('tbe-support::web.nav_tickets');
    }

    /** Open tickets wait for an answer; counted inside the current bot only. */
    public static function waitingCount(): int
    {
        return static::getEloquentQuery()->where('status', TicketStatus::Open->value)->count();
    }

    /** @return list<string> a ticket by its number, or its customer's username or name */
    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'customer.telegramUser.username', 'customer.telegramUser.first_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return '#'.$record->getKey();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof Ticket ? [
            (string) __('tbe-support::web.customer') => People::fullName($record->customer),
            (string) __('tbe-support::web.status') => $record->status->label(),
        ] : [];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return ViewTicket::getUrl(['record' => $record]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('customer.telegramUser');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer.telegramUser', 'lastStaff.telegramUser']))
            ->columns([
                TextColumn::make('id')->label((string) __('tbe-support::web.ticket'))->prefix('#')->sortable()->copyable(),
                TextColumn::make('customer_name')
                    ->label((string) __('tbe-support::web.customer'))
                    ->state(fn (Ticket $record): string => People::fullName($record->customer))
                    ->description(fn (Ticket $record): ?string => People::username($record->customer)),
                TextColumn::make('status')
                    ->label((string) __('tbe-support::web.status'))
                    ->badge()
                    ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                    ->color(TicketStatusColor::of(...)),
                TextColumn::make('last_replier')
                    ->label((string) __('tbe-support::web.last_replier'))
                    ->state(fn (Ticket $record): ?string => $record->lastStaff ? People::fullName($record->lastStaff) : null),
                PanelDates::since(TextColumn::make('last_message_at'))->label((string) __('tbe-support::web.last_message'))->sortable(),
            ])
            // Whoever has waited longest comes first.
            ->defaultSort('last_message_at', 'asc')
            ->poll('30s')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->emptyStateHeading((string) __('tbe-support::web.empty.tickets'))
            ->recordUrl(fn (Ticket $record): string => ViewTicket::getUrl(['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
