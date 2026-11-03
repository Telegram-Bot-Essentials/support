<?php

namespace TelegramBotEssentials\Support\Telegram\Commands\Member;

use TelegramBotEssentials\Essence\Telegram\Commands\Command;
use TelegramBotEssentials\Settings\Contracts\IgnoresChannelLock;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;
use TelegramBotEssentials\Support\Telegram\Features\Member\SupportFeature;

class SupportCommand extends Command implements IgnoresChannelLock, RefusesSuspendedUsers
{
    protected string $name = 'support';

    protected int $priority = 70;

    protected function description(): ?string
    {
        return (string) __('tbe-support::support.command');
    }

    public function handle(): ?bool
    {
        SupportFeature::main()->send();

        return true;
    }
}
