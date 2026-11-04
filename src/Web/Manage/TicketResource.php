<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use TelegramBotEssentials\Essence\Web\Filament\PanelResource;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Web\TicketStatusColor;

class TicketResource extends PanelResource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $ability = 'support.manage';

    protected static ?string $slug = 'tickets';

    protected static ?string $tenantOwnershipRelationshipName = 'bot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

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

    public static function getNavigationBadge(): ?string
    {
        $open = Ticket::query()->where('status', TicketStatus::Open->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer.telegramUser', 'lastStaff.telegramUser']))
            ->columns([
                TextColumn::make('id')->label((string) __('tbe-support::web.ticket'))->prefix('#')->sortable(),
                TextColumn::make('customer_name')
                    ->label((string) __('tbe-support::web.customer'))
                    ->state(fn (Ticket $record): string => People::fullName($record->customer)),
                TextColumn::make('status')
                    ->label((string) __('tbe-support::web.status'))
                    ->badge()
                    ->formatStateUsing(fn (TicketStatus $state): string => $state->label())
                    ->color(TicketStatusColor::of(...)),
                TextColumn::make('last_replier')
                    ->label((string) __('tbe-support::web.last_replier'))
                    ->state(fn (Ticket $record): ?string => $record->lastStaff ? People::fullName($record->lastStaff) : null),
                TextColumn::make('last_message_at')->label((string) __('tbe-support::web.last_message'))->since()->sortable(),
            ])
            // Whoever has waited longest comes first.
            ->defaultSort('last_message_at', 'asc')
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
