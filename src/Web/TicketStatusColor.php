<?php

namespace TelegramBotEssentials\Support\Web;

use TelegramBotEssentials\Support\Enums\TicketStatus;

final class TicketStatusColor
{
    public static function of(TicketStatus $state): string
    {
        return match ($state) {
            TicketStatus::Open => 'warning',
            TicketStatus::Answered => 'success',
            TicketStatus::Closed => 'gray',
        };
    }
}
