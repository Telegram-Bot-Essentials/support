<?php

namespace TelegramBotEssentials\Support\Telegram\Features\Admin;

use Telegram\Bot\Keyboard\Button;
use Telegram\Bot\Keyboard\Keyboard;
use TelegramBotEssentials\Essence\Telegram\TelegramResponse;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Services\People;
use TelegramBotEssentials\Support\Services\TicketHistory;
use TelegramBotEssentials\UserManagement\Telegram\Features\Admin\BotUsersFeature;

class TicketsFeature
{
    public const TYPE = 'TICKETS';

    private const PER_PAGE = 8;

    public static function menu(string $tab = 'open', int $page = 1): TelegramResponse
    {
        $status = TicketStatus::tryFrom($tab) ?? TicketStatus::Open;

        // Who waited longest comes first, except in the closed archive.
        $tickets = Ticket::query()
            ->where('status', $status->value)
            ->with('customer.telegramUser')
            ->orderBy('last_message_at', $status === TicketStatus::Closed ? 'desc' : 'asc')
            ->paginate(perPage: self::PER_PAGE, page: max(1, $page));

        $keys = Keyboard::make()->inline();

        $keys->row(array_map(fn (TicketStatus $tabStatus) => Keyboard::inlineButton([
            'text' => ($tabStatus === $status ? '• ' : '').$tabStatus->label().' ('.Ticket::query()->where('status', $tabStatus->value)->count().')',
            'callback_data' => encodeCallback(self::TYPE, 'start', [$tabStatus->value, 1]),
        ]), TicketStatus::cases()));

        foreach ($tickets as $ticket) {
            $keys->row([Keyboard::inlineButton([
                'text' => (string) __('tbe-support::support.admin.row', [
                    'id' => $ticket->id,
                    'name' => People::fullName($ticket->customer),
                    'date' => $ticket->last_message_at->format('m-d H:i'),
                ]),
                'callback_data' => encodeCallback(self::TYPE, 'show', [$ticket->id, $status->value, $tickets->currentPage()]),
            ])]);
        }

        $navigation = [];
        if ($tickets->currentPage() > 1) {
            $navigation[] = self::button('tbe-support::support.keys.previous', 'start', [$status->value, $tickets->currentPage() - 1]);
        }
        if ($tickets->hasMorePages()) {
            $navigation[] = self::button('tbe-support::support.keys.next', 'start', [$status->value, $tickets->currentPage() + 1]);
        }
        if ($navigation !== []) {
            $keys->row($navigation);
        }

        return new TelegramResponse(
            text: (string) __($tickets->total() === 0 ? 'tbe-support::support.admin.empty' : 'tbe-support::support.admin.title'),
            replyMarkup: $keys,
        );
    }

    public static function show(Ticket $ticket, string $tab = 'open', int $page = 1): TelegramResponse
    {
        $keys = Keyboard::make()->inline();

        $keys->row([self::button('tbe-support::support.keys.reply', 'reply', [$ticket->id])]);

        if ($ticket->messages()->whereNotNull('file_id')->exists()) {
            $keys->row([self::button('tbe-support::support.keys.attachments', 'attachments', [$ticket->id])]);
        }

        if ($ticket->status !== TicketStatus::Closed) {
            $keys->row([self::button('tbe-support::support.keys.close', 'close', [$ticket->id, $tab, $page])]);
        }

        // user-management is optional, and its profile needs its own ability.
        if (class_exists(BotUsersFeature::class) && hasAccess('user-management.users.manage')) {
            $keys->row([Keyboard::inlineButton([
                'text' => (string) __('tbe-support::support.keys.profile'),
                'callback_data' => encodeCallback('BOTUSERS', 'show', [$ticket->bot_user_id]),
            ])]);
        }

        $keys->row([self::button('tbe-support::support.keys.back', 'start', [$tab, $page])]);

        $blocks = array_filter([
            self::summary($ticket),
            ticketContext()->render($ticket),
            TicketHistory::render($ticket, TicketSide::Staff),
        ]);

        return new TelegramResponse(text: implode("\n\n", $blocks), replyMarkup: $keys, parseMode: 'HTML');
    }

    public static function prompt(int $ticketId): TelegramResponse
    {
        return new TelegramResponse(
            text: (string) __('tbe-support::support.admin.reply_prompt', ['id' => $ticketId]),
            replyMarkup: Keyboard::make()->inline()->row([self::button('tbe-support::support.keys.cancel', 'cancelPrompt')]),
        );
    }

    public static function alertHeader(Ticket $ticket, bool $isNew): string
    {
        return (string) __($isNew ? 'tbe-support::support.alerts.new_ticket' : 'tbe-support::support.alerts.customer_replied', [
            'id' => $ticket->id,
            'name' => e(People::fullName($ticket->customer)),
        ]);
    }

    /** @return Keyboard<array-key, mixed> */
    public static function alertKeys(Ticket $ticket): Keyboard
    {
        return Keyboard::make()->inline()->row([
            self::button('tbe-support::support.keys.reply', 'reply', [$ticket->id]),
            self::button('tbe-support::support.keys.open', 'open', [$ticket->id]),
        ]);
    }

    private static function summary(Ticket $ticket): string
    {
        return (string) __('tbe-support::support.admin.show', [
            'id' => $ticket->id,
            'status' => $ticket->status->label(),
            'name' => e(People::fullName($ticket->customer)),
            'username' => e(People::username($ticket->customer) ?? '—'),
            'peer' => People::peerId($ticket->customer),
            'staff' => $ticket->lastStaff !== null ? e(People::fullName($ticket->lastStaff)) : '—',
        ]);
    }

    /**
     * @param  list<int|string>  $params
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
