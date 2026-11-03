<?php

namespace TelegramBotEssentials\Support\DTOs;

use Telegram\Bot\Objects\Message;

/** What one message of a ticket says: text, or a photo or document with an optional caption. */
final readonly class TicketContent
{
    public const KIND_TEXT = 'text';

    public const KIND_PHOTO = 'photo';

    public const KIND_DOCUMENT = 'document';

    public function __construct(
        public string $kind,
        public ?string $text = null,
        public ?string $fileId = null,
    ) {}

    /** Null for anything a ticket does not carry, such as a sticker or a voice message. */
    public static function fromMessage(Message $message): ?self
    {
        $data = $message->toArray();
        $caption = is_string($data['caption'] ?? null) ? $data['caption'] : null;

        if (is_array($data['photo'] ?? null) && $data['photo'] !== []) {
            // Telegram lists a photo's sizes smallest first.
            $largest = end($data['photo']);
            $fileId = is_array($largest) ? ($largest['file_id'] ?? null) : null;

            return is_string($fileId) ? new self(self::KIND_PHOTO, $caption, $fileId) : null;
        }

        if (is_array($data['document'] ?? null) && is_string($data['document']['file_id'] ?? null)) {
            return new self(self::KIND_DOCUMENT, $caption, $data['document']['file_id']);
        }

        if (is_string($data['text'] ?? null) && $data['text'] !== '') {
            return new self(self::KIND_TEXT, $data['text']);
        }

        return null;
    }
}
