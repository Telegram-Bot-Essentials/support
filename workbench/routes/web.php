<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use TelegramBotEssentials\Essence\Models\TelegramUser;
use TelegramBotEssentials\Essence\Web\TelegramLink;
use Workbench\App\Models\User;

Route::get('/', fn () => view('welcome'));

// Workbench only, and only on this port: signs in with no check at all.
if (($_SERVER['SERVER_PORT'] ?? null) === '8795') {
    Route::middleware('web')->get('/dev/login/{peer}/{to}', function (int $peer, string $to) {
        $account = TelegramLink::accountFor($peer);
        if ($account === null) {
            $account = User::createForTelegram(TelegramUser::query()->where('peer_id', $peer)->firstOrFail());
            $account->linkTelegram($peer);
        }
        Auth::guard('tbe-web')->login($account);

        return redirect('/'.$to);
    })->where('to', '.*');
}
