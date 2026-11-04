<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use TelegramBotEssentials\Essence\Web\Filament\PanelUser;

Route::get('/', fn () => view('welcome'));

// Workbench only, and only on this port: signs in with no check at all.
if (($_SERVER['SERVER_PORT'] ?? null) === '8795') {
    Route::middleware('web')->get('/dev/login/{peer}/{to}', function (int $peer, string $to) {
        Auth::guard('tbe-web')->login(PanelUser::query()->where('telegram_user_peer_id', $peer)->firstOrFail());

        return redirect('/'.$to);
    })->where('to', '.*');
}
