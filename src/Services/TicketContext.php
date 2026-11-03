<?php

namespace TelegramBotEssentials\Support\Services;

use Closure;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\Models\Ticket;
use Throwable;

/**
 * What the app adds to the staff's view of a ticket: facts about the customer
 * this package knows nothing of, such as the services they hold.
 */
class TicketContext
{
    /** @var list<Closure(BotUser, Ticket): ?string> */
    private array $blocks = [];

    /** @param  Closure(BotUser, Ticket): ?string  $block  HTML, or null to show nothing */
    public function add(Closure $block): self
    {
        $this->blocks[] = $block;

        return $this;
    }

    /** Each block on its own line, leaving out those with nothing to say. */
    public function render(Ticket $ticket): string
    {
        $lines = [];

        foreach ($this->blocks as $block) {
            try {
                $line = $block($ticket->customer, $ticket);
            } catch (Throwable $e) {
                // Extra detail must not cost the staff the ticket itself.
                report($e);

                continue;
            }

            if ($line !== null && $line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
