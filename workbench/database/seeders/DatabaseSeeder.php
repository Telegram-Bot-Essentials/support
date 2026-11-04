<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Essence\Models\TelegramUser;
use TelegramBotEssentials\Settings\Models\BotSetting;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;

/** A demo bot: 111 is a customer, 222 the owner. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        TelegramUser::query()->firstOrCreate(['peer_id' => 111], TelegramUser::factory()->raw(['peer_id' => 111, 'first_name' => 'Sara']));
        TelegramUser::query()->firstOrCreate(['peer_id' => 222], TelegramUser::factory()->raw(['peer_id' => 222, 'first_name' => 'Elyar']));

        $bot = Bot::query()->firstOrCreate(['unique_id' => 'demo'], Bot::factory()->raw(['unique_id' => 'demo', 'bot_token' => '1:demo', 'bot_owner_peer_id' => 222, 'activated_until' => now()->addYear()]));
        $customer = BotUser::query()->firstOrCreate(['bot_id' => $bot->id, 'telegram_user_peer_id' => 111]);
        $owner = BotUser::query()->firstOrCreate(['bot_id' => $bot->id, 'telegram_user_peer_id' => 222]);

        BotSetting::query()->updateOrCreate(['bot_id' => $bot->id, 'key' => 'support.handles'], ['value' => "@our_support\nWhatsApp: +1 555 0100"]);
        BotSetting::query()->updateOrCreate(['bot_id' => $bot->id, 'key' => 'support.cooldown_seconds'], ['value' => '0']);

        $open = Ticket::query()->create(['bot_id' => $bot->id, 'bot_user_id' => $customer->id, 'status' => TicketStatus::Open, 'last_message_at' => now()->subHours(3)]);
        $open->messages()->create(['bot_id' => $bot->id, 'side' => TicketSide::Customer, 'kind' => 'text', 'text' => 'My service stopped working this morning.', 'sender_id' => $customer->id]);

        $answered = Ticket::query()->create(['bot_id' => $bot->id, 'bot_user_id' => $customer->id, 'status' => TicketStatus::Answered, 'last_staff_id' => $owner->id, 'last_message_at' => now()->subDay()]);
        $answered->messages()->create(['bot_id' => $bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'text' => 'This is the error I get.', 'file_id' => 'demo-file', 'sender_id' => $customer->id]);
        $answered->messages()->create(['bot_id' => $bot->id, 'side' => TicketSide::Staff, 'kind' => 'text', 'text' => 'Please try the new link. It should connect now.', 'sender_id' => $owner->id]);
    }
}
