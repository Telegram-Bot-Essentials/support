<?php

namespace TelegramBotEssentials\Support\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return (string) __('tbe-support::support.status.'.$this->value);
    }
}
