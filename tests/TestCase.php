<?php

declare(strict_types=1);

namespace TelegramBotEssentials\Support\Tests;

use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Testing\TestCase as EssenceTestCase;
use TelegramBotEssentials\Settings\TbeSettingsServiceProvider;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\TbeSupportServiceProvider;

abstract class TestCase extends EssenceTestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            TbeSettingsServiceProvider::class,
            TbeSupportServiceProvider::class,
        ]);
    }

    public function press(Bot $bot, int $peerId, string $type, string $method, array $params = []): void
    {
        $this->postWebhookUpdate($bot, $this->makeCallbackQueryUpdate(encodeCallback($type, $method, $params), $peerId))->assertOk();
    }

    public function say(Bot $bot, int $peerId, string $text, array $extra = []): void
    {
        $update = $this->makeMessageUpdate($text, $peerId);
        $update['message'] = array_merge($update['message'], $extra);
        $this->postWebhookUpdate($bot, $update)->assertOk();
    }

    public function openTicket(Bot $bot, int $peerId = 7001, string $text = 'my vpn is down'): Ticket
    {
        $this->press($bot, $peerId, 'SUPPORT', 'newTicket');
        $this->say($bot, $peerId, $text);

        return Ticket::query()->latest('id')->firstOrFail();
    }
}
