<?php

namespace TelegramBotEssentials\Support\Telegram\ReplyKeys\Member;

use TelegramBotEssentials\Essence\Telegram\ReplyKeys\ReplyKey;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\Telegram\Features\Member\SupportFeature;

class SupportKey extends ReplyKey implements IgnoresChannelLock, RefusesSuspendedUsers
{
    protected function text(): string
    {
        return (string) __('tbe-support::support.reply_key');
    }

    public function handle(): void
    {
        SupportFeature::main()->send();
    }
}
