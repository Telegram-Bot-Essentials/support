<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TelegramBotEssentials\Settings\Models\BotSetting;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketMessage;
use TelegramBotEssentials\Support\Web\Manage\ViewTicket;

const UPLOAD_CUSTOMER = 8201;
const UPLOAD_STAFF = 8203;
// Shaped like Telegram's: the SDK sends a string as a file_id only when it looks like one.
const UPLOAD_PHOTO_ID = 'AgACAgQAAxkBAAIupload-photo_id';
const UPLOAD_DOC_ID = 'BQACAgQAAxkBAAIupload-document_id';

/**
 * Telegram as the panel meets it: a photo or file sent to a chat comes back
 * with a file_id; $refuse lists chat ids whose sends fail, as for a member
 * who blocked the bot.
 *
 * @param  list<int>  $refuse
 */
function uploadTelegram(array $refuse = [], bool $photoTooBig = false): void
{
    $next = 2000;
    $factory = new Factory;
    $factory->fake(function (Request $request) use (&$next, $refuse, $photoTooBig) {
        $url = (string) $request->url();
        $chatId = (int) (uploadField($request, 'chat_id') ?? 1);

        if (in_array($chatId, $refuse, true)) {
            return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
        }

        $message = ['message_id' => $next++, 'date' => now()->timestamp, 'chat' => ['id' => $chatId, 'type' => 'private']];

        if (str_ends_with($url, '/sendPhoto')) {
            if ($photoTooBig) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS'], 400);
            }
            $message['photo'] = [['file_id' => 'small', 'file_unique_id' => 's'], ['file_id' => UPLOAD_PHOTO_ID, 'file_unique_id' => 'p']];
        }
        if (str_ends_with($url, '/sendDocument')) {
            $message['document'] = ['file_id' => UPLOAD_DOC_ID, 'file_unique_id' => 'd'];
        }

        return Http::response(['ok' => true, 'result' => str_ends_with($url, '/deleteMessage') ? true : $message]);
    });
    Http::swap($factory);
}

/** A field of a Telegram call, sent as JSON, a form or multipart. */
function uploadField(Request $request, string $name): ?string
{
    if ($request->isMultipart()) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return is_scalar($part['contents'] ?? null) ? (string) $part['contents'] : null;
            }
        }

        return null;
    }

    $value = $request->data()[$name] ?? null;

    return is_scalar($value) ? (string) $value : null;
}

/** @return list<Request> Telegram calls to $method */
function uploadCalls(string $method): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with((string) $r->url(), '/'.$method))
        ->values()->all();
}

beforeEach(function () {
    uploadTelegram();
    $this->bot = $this->makeBot(['bot_owner_peer_id' => UPLOAD_STAFF]);
    $this->customer = $this->makeBotUser($this->bot, UPLOAD_CUSTOMER);
    $this->staff = $this->grant($this->makeBotUser($this->bot, UPLOAD_STAFF), 'support.manage', 'web.manage');
    BotSetting::query()->updateOrCreate(['bot_id' => $this->bot->id, 'key' => 'support.cooldown_seconds'], ['value' => '0']);

    $this->ticket = Ticket::query()->create(['bot_id' => $this->bot->id, 'bot_user_id' => $this->customer->id, 'status' => TicketStatus::Open, 'last_message_at' => now()]);
    $this->ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'text', 'text' => 'my vpn is down', 'sender_id' => $this->customer->id]);
    $this->enterPanel('manage', $this->bot, $this->staff);
});

function staffReply(): ?TicketMessage
{
    return Ticket::query()->latest('id')->first()?->messages()->where('side', TicketSide::Staff->value)->latest('id')->first();
}

it('sends a photo with the reply as its caption', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['reply' => 'Here is the setting', 'attachment' => UploadedFile::fake()->image('screen.png')])
        ->call('send')
        ->assertHasNoFormErrors()
        ->assertNotified((string) __('tbe-support::web.sent'));

    expect(staffReply())->kind->toBe('photo')->file_id->toBe(UPLOAD_PHOTO_ID)->text->toBe('Here is the setting');

    // Staged in the staff's own chat, silently, then taken away.
    $staged = uploadCalls('sendPhoto')[0];
    expect(uploadField($staged, 'chat_id'))->toBe((string) UPLOAD_STAFF)
        ->and(uploadField($staged, 'disable_notification'))->toBeIn(['1', 'true'])
        ->and(uploadField(uploadCalls('deleteMessage')[0], 'chat_id'))->toBe((string) UPLOAD_STAFF);

    // The customer gets Telegram's copy, by file_id.
    $delivered = collect(uploadCalls('sendPhoto'))->first(fn (Request $r) => uploadField($r, 'chat_id') === (string) UPLOAD_CUSTOMER);
    expect($delivered)->not->toBeNull()
        ->and(uploadField($delivered, 'photo'))->toBe(UPLOAD_PHOTO_ID);
});

it('sends any other file as a document, with no reply text needed', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['attachment' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')])
        ->call('send')
        ->assertHasNoFormErrors();

    expect(staffReply())->kind->toBe('document')->file_id->toBe(UPLOAD_DOC_ID)->text->toBeNull()
        ->and(uploadCalls('sendPhoto'))->toBe([]);

    $delivered = collect(uploadCalls('sendDocument'))->first(fn (Request $r) => uploadField($r, 'chat_id') === (string) UPLOAD_CUSTOMER);
    expect(uploadField($delivered, 'document'))->toBe(UPLOAD_DOC_ID);
});

it('sends an image Telegram refuses as a photo as a document', function () {
    uploadTelegram(photoTooBig: true);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['attachment' => UploadedFile::fake()->image('wide.png', 9000, 10)])
        ->call('send')
        ->assertHasNoFormErrors();

    expect(staffReply())->kind->toBe('document')->file_id->toBe(UPLOAD_DOC_ID);
});

it('keeps the reply when the customer blocked the bot', function () {
    uploadTelegram(refuse: [UPLOAD_CUSTOMER]);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['attachment' => UploadedFile::fake()->image('screen.png')])
        ->call('send')
        ->assertNotified((string) __('tbe-support::support.answers.not_delivered'));

    expect(staffReply())->file_id->toBe(UPLOAD_PHOTO_ID);
});

it('records nothing when Telegram will not take the file', function () {
    uploadTelegram(refuse: [UPLOAD_STAFF]);

    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['reply' => 'see attached', 'attachment' => UploadedFile::fake()->image('screen.png')])
        ->call('send')
        ->assertNotified((string) __('tbe-support::web.upload_failed'))
        ->assertSchemaStateSet(['reply' => 'see attached'], 'form');

    expect(staffReply())->toBeNull();
});

it('still sends a text-only reply', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['reply' => 'Restart the app'])
        ->call('send')
        ->assertHasNoFormErrors();

    expect(staffReply())->kind->toBe('text')->text->toBe('Restart the app')
        ->and(uploadCalls('deleteMessage'))->toBe([]);
});

it('refuses an empty reply without a file', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['reply' => ''])
        ->call('send')
        ->assertHasFormErrors(['reply']);

    expect(staffReply())->toBeNull();
});

it('refuses a file over the limit', function () {
    Livewire::test(ViewTicket::class, ['record' => $this->ticket->id])
        ->fillForm(['attachment' => UploadedFile::fake()->create('huge.zip', 11 * 1024, 'application/zip')])
        ->call('send')
        ->assertHasFormErrors(['attachment']);

    expect(staffReply())->toBeNull();
});
