<?php

namespace TelegramBotEssentials\Support\Enums;

enum SupportMode: string
{
    case Both = 'both';
    case Tickets = 'tickets';
    case Handles = 'handles';

    public static function current(): self
    {
        $mode = settings()->get('support.mode');

        return is_string($mode) ? (self::tryFrom($mode) ?? self::Both) : self::Both;
    }

    public function allowsTickets(): bool
    {
        return $this !== self::Handles;
    }

    public function showsHandles(): bool
    {
        return $this !== self::Tickets;
    }
}
