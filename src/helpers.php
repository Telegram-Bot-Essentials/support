<?php

use TelegramBotEssentials\Support\Services\TicketContext;

if (! function_exists('ticketContext')) {
    /** Where an app adds its own facts about a customer to the staff's view of a ticket. */
    function ticketContext(): TicketContext
    {
        return app(TicketContext::class);
    }
}
