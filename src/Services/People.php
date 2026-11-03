<?php

namespace TelegramBotEssentials\Support\Services;

use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Essence\Models\TelegramUser;

/** How a bot user is named and reached. */
class People
{
    public static function peerId(BotUser $user): int
    {
        $peerId = $user->getAttribute('telegram_user_peer_id');

        return is_numeric($peerId) ? (int) $peerId : 0;
    }

    public static function fullName(BotUser $user): string
    {
        $telegramUser = $user->telegramUser;
        $name = $telegramUser instanceof TelegramUser ? $telegramUser->getFullNameAttribute() : '';

        return $name !== '' ? $name : '#'.self::peerId($user);
    }

    public static function username(BotUser $user): ?string
    {
        $telegramUser = $user->telegramUser;
        $username = $telegramUser instanceof TelegramUser ? $telegramUser->username : null;

        return $username === null || $username === '' ? null : '@'.$username;
    }
}
