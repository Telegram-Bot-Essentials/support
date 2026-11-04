<?php

declare(strict_types=1);

namespace TelegramBotEssentials\Support\Tests;

use Filament\Facades\Filament;
use Telegram\Bot\Objects\Update;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Essence\Testing\WebTestCase as EssenceWebTestCase;
use TelegramBotEssentials\Essence\Web\Filament\PanelBot;
use TelegramBotEssentials\Essence\Web\Filament\PanelUser;
use TelegramBotEssentials\Settings\TbeSettingsServiceProvider;
use TelegramBotEssentials\Support\TbeSupportServiceProvider;

abstract class WebTestCase extends EssenceWebTestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            TbeSettingsServiceProvider::class,
            TbeSupportServiceProvider::class,
        ]);
    }

    /** Signs $user in on $panel with what the panel's middleware would have set, for Livewire::test(). */
    public function enterPanel(string $panel, Bot $bot, BotUser $user): PanelUser
    {
        $panelUser = PanelUser::query()->findOrFail($user->getKey());
        $this->actingAs($panelUser, 'tbe-web');

        Filament::setCurrentPanel($panel);
        Filament::setTenant(PanelBot::query()->findOrFail($bot->getKey()), true);
        Filament::bootCurrentPanel();
        tenancy()->initialize($bot);

        $hook = wHook();
        $hook->setBot($bot);
        $hook->setApi(telegramApi((string) $bot->getAttribute('bot_token')));
        $hook->setUpdate(new Update([]));
        $hook->setUser($panelUser);

        return $panelUser;
    }
}
