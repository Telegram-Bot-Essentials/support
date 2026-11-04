<?php

namespace TelegramBotEssentials\Support\Web;

use Filament\Contracts\Plugin;
use Filament\Panel;
use TelegramBotEssentials\Support\Web\Customer\TicketPage;
use TelegramBotEssentials\Support\Web\Customer\TicketsPage;

class SupportCustomerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'tbe-support-customer';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([TicketsPage::class, TicketPage::class]);
    }

    public function boot(Panel $panel): void {}
}
