<?php

namespace TelegramBotEssentials\Support\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Support\Models\TicketMessage;
use Throwable;

/**
 * Hands a message's attachment to a browser. Tickets keep only Telegram's
 * file_id, and Telegram's download URL contains the bot token, so the file
 * always goes through us. Callers decide who may ask for which message.
 */
class TicketFiles
{
    /** @throws FeatureIsDisabled when Telegram will not give the file, e.g. over its 20 MB limit */
    public function download(TicketMessage $message): StreamedResponse
    {
        $fileId = $message->file_id;
        $token = wHook()->bot()->getAttribute('bot_token');

        try {
            $path = wHook()->api()->getFile(['file_id' => $fileId])->get('file_path');
            $response = Http::timeout(60)->get(Str::beforeLast(config()->string('tbe-essence.base_bot_url', 'https://api.telegram.org/bot'), '/bot').'/file/bot'.(is_string($token) ? $token : '').'/'.(is_string($path) ? $path : ''));
            $response->throw();
        } catch (Throwable $e) {
            report($e);

            throw new FeatureIsDisabled((string) __('tbe-support::web.file_unavailable'));
        }

        $body = $response->body();
        $name = 'ticket-'.$message->ticket_id.'-'.$message->id.(is_string($path) && pathinfo($path, PATHINFO_EXTENSION) !== '' ? '.'.pathinfo($path, PATHINFO_EXTENSION) : '');

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $name, ['Content-Type' => 'application/octet-stream']);
    }
}
