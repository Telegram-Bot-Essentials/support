<?php

namespace TelegramBotEssentials\Support\Services;

use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketMessage;

/** A ticket's latest messages as text, newest kept when they do not all fit in one Telegram message. */
class TicketHistory
{
    private const LIMIT = 3000;

    private const MESSAGE_LIMIT = 600;

    private const MESSAGES = 10;

    public static function render(Ticket $ticket, TicketSide $viewer): string
    {
        $messages = $ticket->messages()->with('sender.telegramUser')->latest('id')->limit(self::MESSAGES)->get();

        $lines = [];
        $length = 0;

        foreach ($messages as $message) {
            $text = (string) $message->text;
            $body = e(mb_strimwidth($text, 0, self::MESSAGE_LIMIT, '…'));
            $attachment = $message->kind === TicketContent::KIND_TEXT ? '' : '📎 ';
            $line = '<b>'.e(self::author($message, $viewer)).'</b> ('.$message->created_at?->format('m-d H:i')."):\n".$attachment.$body;

            if ($length + mb_strlen($line) > self::LIMIT) {
                break;
            }

            $lines[] = $line;
            $length += mb_strlen($line);
        }

        return implode("\n\n", array_reverse($lines));
    }

    private static function author(TicketMessage $message, TicketSide $viewer): string
    {
        if ($message->side === TicketSide::Customer) {
            return (string) __('tbe-support::support.history.'.($viewer === TicketSide::Customer ? 'you' : 'customer'));
        }

        // Customers see the team, never who on it answered.
        return $viewer === TicketSide::Staff && $message->sender !== null
            ? People::fullName($message->sender)
            : (string) __('tbe-support::support.history.support');
    }
}
