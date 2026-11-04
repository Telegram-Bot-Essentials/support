<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TelegramBotEssentials\Essence\Models\BotUser;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // An app decides who holds abilities; here the bot's owner holds them all.
        Gate::before(fn (mixed $user) => $user instanceof BotUser
            && (string) $user->getAttribute('telegram_user_peer_id') === (string) $user->bot?->getAttribute('bot_owner_peer_id') ? true : null);
    }
}
