<?php

namespace TelegramBotEssentials\Support\Gates;

use TelegramBotEssentials\Essence\Contracts\HandlerGate;
use TelegramBotEssentials\Support\Contracts\RefusesSuspendedUsers;

/** Nothing in the suite refuses a suspended user yet, so support does it for its own handlers. */
class SuspendedUserGate implements HandlerGate
{
    public function check(object $handler): void
    {
        if (! $handler instanceof RefusesSuspendedUsers || hasAccess('support.manage')) {
            return;
        }

        dependsOn(wHook()->user()->getAttribute('suspended_at') === null, (string) __('tbe-support::support.errors.suspended'));
    }
}
