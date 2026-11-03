<?php

namespace TelegramBotEssentials\Support\Services;

use Illuminate\Support\Facades\DB;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Enums\SupportMode;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketMessage;

/** Everything that changes a ticket. Each change is saved first, then sent. */
class Tickets
{
    public function __construct(private Relay $relay) {}

    /** @throws FeatureIsDisabled when the bot takes no tickets or this customer is over a limit */
    public function open(BotUser $customer, TicketContent $content): Ticket
    {
        $this->ensureCanOpen($customer);
        $this->ensureNotTooSoon($customer);

        [$ticket, $message] = DB::transaction(function () use ($customer, $content) {
            $ticket = Ticket::query()->create([
                'bot_id' => $customer->getAttribute('bot_id'),
                'bot_user_id' => $customer->getKey(),
                'status' => TicketStatus::Open,
                'last_message_at' => now(),
            ]);

            return [$ticket, $this->record($ticket, $customer, TicketSide::Customer, $content)];
        });

        $this->relay->toStaff($ticket, $message, isNew: true);

        return $ticket;
    }

    /** @throws FeatureIsDisabled when the customer is writing too fast */
    public function replyAsCustomer(Ticket $ticket, BotUser $customer, TicketContent $content): void
    {
        $this->ensureNotTooSoon($customer);

        $message = DB::transaction(function () use ($ticket, $customer, $content) {
            $ticket->update(['status' => TicketStatus::Open, 'last_message_at' => now(), 'closed_at' => null]);

            return $this->record($ticket, $customer, TicketSide::Customer, $content);
        });

        $this->relay->toStaff($ticket, $message, isNew: false);
    }

    /** Whether it reached the customer. It is kept either way. */
    public function replyAsStaff(Ticket $ticket, BotUser $staff, TicketContent $content): bool
    {
        $message = DB::transaction(function () use ($ticket, $staff, $content) {
            $ticket->update([
                'status' => TicketStatus::Answered,
                'last_staff_id' => $staff->getKey(),
                'last_message_at' => now(),
                'closed_at' => null,
            ]);

            return $this->record($ticket, $staff, TicketSide::Staff, $content);
        });

        return $this->relay->toCustomer($ticket, $message);
    }

    public function closeAsCustomer(Ticket $ticket): void
    {
        $this->close($ticket);
    }

    public function closeAsStaff(Ticket $ticket): void
    {
        if ($this->close($ticket)) {
            $this->relay->notifyCustomer($ticket, (string) __('tbe-support::support.alerts.closed_by_staff', ['id' => $ticket->id]));
        }
    }

    /** Answered tickets nobody came back to. Run in the context of the ticket's bot. */
    public function closeStale(Bot $bot, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $closed = 0;

        Ticket::query()
            ->where('bot_id', $bot->getKey())
            ->where('status', TicketStatus::Answered->value)
            ->where('last_message_at', '<=', now()->subDays($days))
            ->with('customer')
            ->chunkById(100, function ($tickets) use ($days, &$closed) {
                foreach ($tickets as $ticket) {
                    if ($this->close($ticket)) {
                        $this->relay->notifyCustomer($ticket, (string) __('tbe-support::support.alerts.closed_stale', ['id' => $ticket->id, 'days' => $days]));
                        $closed++;
                    }
                }
            });

        return $closed;
    }

    /** @throws FeatureIsDisabled */
    public function ensureCanOpen(BotUser $customer): void
    {
        dependsOn(SupportMode::current()->allowsTickets(), (string) __('tbe-support::support.errors.tickets_off'));

        $limit = self::number('support.max_open_tickets');

        dependsOn(
            $limit === 0 || Ticket::query()->notClosed()->where('bot_user_id', $customer->getKey())->count() < $limit,
            (string) __('tbe-support::support.errors.too_many_open', ['limit' => $limit]),
        );
    }

    /** @throws FeatureIsDisabled */
    private function ensureNotTooSoon(BotUser $customer): void
    {
        $cooldown = self::number('support.cooldown_seconds');

        if ($cooldown === 0) {
            return;
        }

        dependsOn(
            ! TicketMessage::query()
                ->where('sender_id', $customer->getKey())
                ->where('side', TicketSide::Customer->value)
                ->where('created_at', '>', now()->subSeconds($cooldown))
                ->exists(),
            (string) __('tbe-support::support.errors.too_soon', ['seconds' => $cooldown]),
        );
    }

    /** Whether this closed it, so a second press tells nobody twice. */
    private function close(Ticket $ticket): bool
    {
        return Ticket::query()
            ->whereKey($ticket->getKey())
            ->where('status', '!=', TicketStatus::Closed->value)
            ->update(['status' => TicketStatus::Closed->value, 'closed_at' => now()]) === 1;
    }

    private function record(Ticket $ticket, BotUser $sender, TicketSide $side, TicketContent $content): TicketMessage
    {
        return $ticket->messages()->create([
            'bot_id' => $ticket->bot_id,
            'sender_id' => $sender->getKey(),
            'side' => $side->value,
            'kind' => $content->kind,
            'text' => $content->text,
            'file_id' => $content->fileId,
        ]);
    }

    public static function number(string $setting): int
    {
        $value = settings()->get($setting);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
