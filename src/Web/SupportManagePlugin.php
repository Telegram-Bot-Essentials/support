<?php

namespace TelegramBotEssentials\Support\Web;

use Filament\Contracts\Plugin;
use Filament\Panel;
use TelegramBotEssentials\Support\Web\Manage\TicketResource;

class SupportManagePlugin implements Plugin
{
    public function getId(): string
    {
        return 'tbe-support-manage';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([TicketResource::class]);
    }

    public function boot(Panel $panel): void {}
}
