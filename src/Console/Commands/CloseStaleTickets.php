<?php

namespace TelegramBotEssentials\Support\Console\Commands;

use Illuminate\Console\Command;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\Tickets;

class CloseStaleTickets extends Command
{
    protected $signature = 'tbe:support:close-stale';

    protected $description = 'Close answered tickets the customer has not come back to';

    public function handle(Tickets $tickets): void
    {
        $closed = 0;

        $botIds = Ticket::query()->where('status', TicketStatus::Answered->value)->distinct()->pluck('bot_id');

        Bot::query()->whereIn('id', $botIds)->each(function (Bot $bot) use ($tickets, &$closed) {
            // Each bot has its own setting and token, so each runs as itself.
            wHook()->runInContext($bot, null, null, null, function () use ($bot, $tickets, &$closed) {
                $closed += $tickets->closeStale($bot, Tickets::number('support.auto_close_days'));
            });
        });

        $this->info("Closed {$closed} stale ticket(s).");
    }
}
