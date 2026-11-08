<?php

namespace Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use TelegramBotEssentials\Essence\Models\TelegramUser;
use TelegramBotEssentials\Essence\Web\Filament\BotPanelOnly;
use TelegramBotEssentials\Essence\Web\HasTelegramAccount;
use TelegramBotEssentials\Essence\Web\TelegramAccount;
use Workbench\Database\Factories\UserFactory;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants, TelegramAccount
{
    use BotPanelOnly, HasTelegramAccount;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The skeleton's users table wants an email and a password: make them up. */
    public static function createForTelegram(TelegramUser $telegramUser): static
    {
        return static::query()->create([
            'name' => $telegramUser->first_name ?: (string) $telegramUser->peer_id,
            'email' => $telegramUser->peer_id.'@telegram.invalid',
            'password' => Str::random(40),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
