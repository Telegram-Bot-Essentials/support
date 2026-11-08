<?php

declare(strict_types=1);

namespace TelegramBotEssentials\Support\Tests;

use TelegramBotEssentials\Essence\Testing\WebTestCase as EssenceWebTestCase;
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
}
