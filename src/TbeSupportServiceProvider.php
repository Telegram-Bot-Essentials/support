<?php

namespace TelegramBotEssentials\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use TelegramBotEssentials\Settings\DTOs\Setting;
use TelegramBotEssentials\Settings\Enums\SettingType;
use TelegramBotEssentials\Support\Console\Commands\CloseStaleTickets;
use TelegramBotEssentials\Support\Enums\SupportMode;
use TelegramBotEssentials\Support\Gates\SuspendedUserGate;
use TelegramBotEssentials\Support\Services\Relay;
use TelegramBotEssentials\Support\Services\TicketContext;
use TelegramBotEssentials\Support\Telegram\CallbackQueries\Admin\TicketsQuery;
use TelegramBotEssentials\Support\Telegram\CallbackQueries\Member\SupportQuery;
use TelegramBotEssentials\Support\Telegram\Commands\Member\SupportCommand;
use TelegramBotEssentials\Support\Telegram\StateAnswers\Admin\TicketsAnswer;
use TelegramBotEssentials\Support\Telegram\StateAnswers\Member\SupportAnswer;
use TelegramBotEssentials\Support\Telegram\TextMatchers\NativeReplyMatcher;
use TelegramBotEssentials\Support\Web\SupportCustomerPlugin;
use TelegramBotEssentials\Support\Web\SupportManagePlugin;

/**
 * The app lists the reply keys in config('tbe-essence.keyboard'):
 * Telegram\ReplyKeys\Member\SupportKey and Telegram\ReplyKeys\Admin\TicketsKey.
 */
class TbeSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TicketContext::class);

        webPanels()->manage(SupportManagePlugin::class)->customer(SupportCustomerPlugin::class);
    }

    public function boot(): void
    {
        $this->registerPublishing();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'tbe-support');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tbe-support');

        callbackQueryBus()->addCallbackQueries([
            SupportQuery::class,
            TicketsQuery::class,
        ]);

        stateAnswerBus()->addStateAnswers([
            SupportAnswer::class,
            TicketsAnswer::class,
        ]);

        commandBus()->addCommands([
            SupportCommand::class,
        ]);

        textMatcherBus()->addTextMatchers([
            NativeReplyMatcher::class,
        ]);

        handlerGates()->add(SuspendedUserGate::class);

        $this->registerSettings();

        if ($this->app->runningInConsole()) {
            $this->commands([CloseStaleTickets::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(CloseStaleTickets::class)->hourly();
        });
    }

    private function registerSettings(): void
    {
        settings()->addSetting(new Setting(
            key: 'support',
            label: fn () => (string) __('tbe-support::support.settings.label'),
            type: SettingType::DIRECTORY,
            description: fn () => (string) __('tbe-support::support.settings.description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.mode',
            label: fn () => (string) __('tbe-support::support.settings.mode'),
            type: SettingType::SELECT,
            default: SupportMode::Both->value,
            options: fn () => [
                SupportMode::Both->value => (string) __('tbe-support::support.settings.mode_both'),
                SupportMode::Tickets->value => (string) __('tbe-support::support.settings.mode_tickets'),
                SupportMode::Handles->value => (string) __('tbe-support::support.settings.mode_handles'),
            ],
            description: fn () => (string) __('tbe-support::support.settings.mode_description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.handles',
            label: fn () => (string) __('tbe-support::support.settings.handles'),
            type: SettingType::TEXT,
            description: fn () => (string) __('tbe-support::support.settings.handles_description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.alert_targets',
            label: fn () => (string) __('tbe-support::support.settings.alert_targets'),
            type: SettingType::MULTISELECT,
            default: [Relay::TARGET_OWNER],
            options: fn () => app(Relay::class)->targets(),
            description: fn () => (string) __('tbe-support::support.settings.alert_targets_description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.max_open_tickets',
            label: fn () => (string) __('tbe-support::support.settings.max_open_tickets'),
            type: SettingType::NUMBER,
            default: 3,
            description: fn () => (string) __('tbe-support::support.settings.max_open_tickets_description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.cooldown_seconds',
            label: fn () => (string) __('tbe-support::support.settings.cooldown_seconds'),
            type: SettingType::NUMBER,
            default: 10,
            description: fn () => (string) __('tbe-support::support.settings.cooldown_seconds_description'),
        ));

        settings()->addSetting(new Setting(
            key: 'support.auto_close_days',
            label: fn () => (string) __('tbe-support::support.settings.auto_close_days'),
            type: SettingType::NUMBER,
            default: 7,
            description: fn () => (string) __('tbe-support::support.settings.auto_close_days_description'),
        ));
    }

    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../lang' => resource_path('lang/vendor/tbe-support'),
            ], 'tbe-support-translations');
        }
    }
}
