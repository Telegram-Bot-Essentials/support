<?php

namespace TelegramBotEssentials\Support\Web\Manage;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use TelegramBotEssentials\Essence\Web\Filament\Concerns\GuardsResourcePage;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;

class ListTickets extends ListRecords
{
    use GuardsResourcePage;

    protected static string $resource = TicketResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (TicketStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge(Ticket::query()->where('status', $status->value)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
