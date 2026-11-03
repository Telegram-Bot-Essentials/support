<?php

namespace TelegramBotEssentials\Support\Telegram\Features\Member;

use Illuminate\Database\Eloquent\Builder;
use Telegram\Bot\Keyboard\Button;
use Telegram\Bot\Keyboard\Keyboard;
use TelegramBotEssentials\Essence\Telegram\TelegramResponse;
use TelegramBotEssentials\Support\Enums\SupportMode;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\TicketHistory;

class SupportFeature
{
    public const TYPE = 'SUPPORT';

    private const PER_PAGE = 5;

    public static function main(): TelegramResponse
    {
        $mode = SupportMode::current();
        $handles = self::handles($mode);

        $text = match (true) {
            $mode->allowsTickets() && $handles !== null => __('tbe-support::support.main.tickets_and_handles', ['handles' => $handles]),
            $mode->allowsTickets() => __('tbe-support::support.main.tickets_only'),
            $handles !== null => __('tbe-support::support.main.handles_only', ['handles' => $handles]),
            default => __('tbe-support::support.main.nothing'),
        };

        $keys = Keyboard::make()->inline();

        if ($mode->allowsTickets()) {
            $keys->row([self::button('tbe-support::support.keys.new_ticket', 'newTicket')]);
        }

        // Tickets from before the owner turned them off stay reachable.
        if ($mode->allowsTickets() || self::customerTickets()->exists()) {
            $keys->row([self::button('tbe-support::support.keys.my_tickets', 'tickets')]);
        }

        return new TelegramResponse(text: (string) $text, replyMarkup: $keys, parseMode: 'HTML');
    }

    public static function tickets(int $page = 1): TelegramResponse
    {
        $tickets = self::customerTickets()->orderByDesc('last_message_at')->paginate(perPage: self::PER_PAGE, page: max(1, $page));

        $keys = Keyboard::make()->inline();

        foreach ($tickets as $ticket) {
            $keys->row([Keyboard::inlineButton([
                'text' => (string) __('tbe-support::support.tickets.row', [
                    'id' => $ticket->id,
                    'status' => $ticket->status->label(),
                    'date' => $ticket->last_message_at->format('Y-m-d'),
                ]),
                'callback_data' => encodeCallback(self::TYPE, 'show', [$ticket->id, $tickets->currentPage()]),
            ])]);
        }

        $navigation = [];
        if ($tickets->currentPage() > 1) {
            $navigation[] = self::button('tbe-support::support.keys.previous', 'tickets', [$tickets->currentPage() - 1]);
        }
        if ($tickets->hasMorePages()) {
            $navigation[] = self::button('tbe-support::support.keys.next', 'tickets', [$tickets->currentPage() + 1]);
        }
        if ($navigation !== []) {
            $keys->row($navigation);
        }

        $keys->row([self::button('tbe-support::support.keys.back', 'start')]);

        return new TelegramResponse(
            text: (string) __($tickets->total() === 0 ? 'tbe-support::support.tickets.empty' : 'tbe-support::support.tickets.title'),
            replyMarkup: $keys,
        );
    }

    public static function show(Ticket $ticket, int $page = 1): TelegramResponse
    {
        $keys = Keyboard::make()->inline();

        $keys->row([self::button('tbe-support::support.keys.reply', 'reply', [$ticket->id])]);

        if ($ticket->messages()->whereNotNull('file_id')->exists()) {
            $keys->row([self::button('tbe-support::support.keys.attachments', 'attachments', [$ticket->id])]);
        }

        if ($ticket->status !== TicketStatus::Closed) {
            $keys->row([self::button('tbe-support::support.keys.close', 'close', [$ticket->id, $page])]);
        }

        $keys->row([self::button('tbe-support::support.keys.back', 'tickets', [$page])]);

        return new TelegramResponse(
            text: __('tbe-support::support.tickets.show', ['id' => $ticket->id, 'status' => $ticket->status->label()])
                ."\n\n".TicketHistory::render($ticket, TicketSide::Customer),
            replyMarkup: $keys,
            parseMode: 'HTML',
        );
    }

    public static function prompt(string $textKey): TelegramResponse
    {
        return new TelegramResponse(
            text: (string) __($textKey),
            replyMarkup: Keyboard::make()->inline()->row([self::button('tbe-support::support.keys.cancel', 'cancelPrompt')]),
        );
    }

    public static function alertHeader(Ticket $ticket): string
    {
        return (string) __('tbe-support::support.alerts.staff_replied', ['id' => $ticket->id]);
    }

    /** @return Keyboard<array-key, mixed> */
    public static function alertKeys(Ticket $ticket): Keyboard
    {
        return Keyboard::make()->inline()->row([
            self::button('tbe-support::support.keys.reply', 'reply', [$ticket->id]),
            self::button('tbe-support::support.keys.open', 'open', [$ticket->id]),
        ]);
    }

    /** Escaped, since owners type this free-form. Telegram still links any @handle in it. */
    private static function handles(SupportMode $mode): ?string
    {
        $handles = settings()->get('support.handles');

        return $mode->showsHandles() && is_string($handles) && trim($handles) !== '' ? e(trim($handles)) : null;
    }

    /** @return Builder<Ticket> */
    private static function customerTickets(): Builder
    {
        return Ticket::query()->where('bot_user_id', wHook()->user()->getKey());
    }

    /**
     * @param  list<int>  $params
     * @return Button<string, string>
     */
    private static function button(string $textKey, string $method, array $params = []): Button
    {
        return Button::make([
            'text' => (string) __($textKey),
            'callback_data' => encodeCallback(self::TYPE, $method, $params),
        ]);
    }
}
