<?php

namespace TelegramBotEssentials\Support\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;
use TelegramBotEssentials\Essence\Exceptions\FeatureIsDisabled;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\DTOs\TicketContent;
use TelegramBotEssentials\Support\Models\TicketMessage;
use Throwable;

/**
 * Moves attachments between a browser and Telegram. Tickets keep only
 * Telegram's file_id, and Telegram's download URL contains the bot token,
 * so files always go through us. Callers decide who may ask for which message.
 */
class TicketFiles
{
    /** Telegram takes photos up to 10 MB, and getFile hands back no more than 20. */
    public const MAX_UPLOAD_KB = 10240;

    private const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * A file from the panel, as ticket content the bot can send on. Telegram
     * gives a file_id only for a file it has sent somewhere, so it goes to
     * $staff's own chat, silently, and is deleted from there at once.
     *
     * @throws FeatureIsDisabled when Telegram will not take it, e.g. $staff blocked the bot
     */
    public function upload(BotUser $staff, string $contents, string $filename, ?string $mimeType, ?string $caption): TicketContent
    {
        $chatId = People::peerId($staff);

        try {
            $sent = $this->stage($chatId, $contents, $filename, in_array($mimeType, self::PHOTO_TYPES, true));
        } catch (Throwable $e) {
            report($e);

            throw new FeatureIsDisabled((string) __('tbe-support::web.upload_failed'));
        }

        try {
            wHook()->api()->deleteMessage(['chat_id' => $chatId, 'message_id' => $sent->get('message_id')]);
        } catch (Throwable) {
            // Left in the staff's own chat: harmless.
        }

        // Kind and file_id from what Telegram made of it: a document's file_id is no photo.
        $staged = TicketContent::fromMessage($sent);
        if ($staged === null || $staged->fileId === null) {
            throw new FeatureIsDisabled((string) __('tbe-support::web.upload_failed'));
        }

        return new TicketContent($staged->kind, $caption !== null && $caption !== '' ? $caption : null, $staged->fileId);
    }

    private function stage(int $chatId, string $contents, string $filename, bool $asPhoto): Message
    {
        $params = ['chat_id' => $chatId, 'disable_notification' => true];

        if ($asPhoto) {
            try {
                return wHook()->api()->sendPhoto([...$params, 'photo' => InputFile::createFromContents($contents, $filename)]);
            } catch (TelegramSDKException) {
                // Too large or oddly shaped for a photo: it still goes as a file.
            }
        }

        return wHook()->api()->sendDocument([...$params, 'document' => InputFile::createFromContents($contents, $filename)]);
    }

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
