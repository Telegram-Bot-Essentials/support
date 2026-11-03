<?php

namespace TelegramBotEssentials\Support\Telegram\ReplyKeys\Admin;

use TelegramBotEssentials\Essence\Telegram\ReplyKeys\ReplyKey;
use TelegramBotEssentials\Support\Telegram\Features\Admin\TicketsFeature;

/** Its label stays fixed: a count in it would stop matching once the count moved. Counts are on the tabs. */
class TicketsKey extends ReplyKey
{
    protected ?string $ability = 'support.manage';

    protected function text(): string
    {
        return (string) __('tbe-support::support.admin.reply_key');
    }

    public function handle(): void
    {
        TicketsFeature::menu()->send();
    }
}
