<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Settings\Models\BotSetting;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Models\TicketLink;
use TelegramBotEssentials\Support\Models\TicketMessage;
use TelegramBotEssentials\Support\Telegram\ReplyKeys\Admin\TicketsKey;
use TelegramBotEssentials\Support\Telegram\ReplyKeys\Member\SupportKey;

const CUSTOMER = 7001;
const ADMIN = 7002;

function setting(Bot $bot, string $key, string $value): void
{
    BotSetting::query()->updateOrCreate(['bot_id' => $bot->id, 'key' => $key], ['value' => $value]);
}

/** Every send answers with a new message id, as Telegram does. */
function fakeTelegram(array $blockedChats = []): void
{
    $next = 1000;
    $factory = new Factory;
    $factory->fake(function ($request) use (&$next, $blockedChats) {
        if (in_array((int) ($request['chat_id'] ?? 0), $blockedChats, true)) {
            return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
        }

        return Http::response(['ok' => true, 'result' => [
            'message_id' => $next++,
            'date' => now()->timestamp,
            'chat' => ['id' => (int) ($request['chat_id'] ?? 1), 'type' => 'private'],
        ]]);
    });
    Http::swap($factory);
}

function sentTo(int $chatId): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => (int) ($pair[0]['chat_id'] ?? 0) === $chatId && preg_match('#/send(Message|Photo|Document)#', (string) $pair[0]->url()))
        ->map(fn ($pair) => (string) ($pair[0]['text'] ?? $pair[0]['caption'] ?? ''))
        ->values()
        ->all();
}

beforeEach(function () {
    fakeTelegram();
    replyKeyBus()->addReplyKeys([SupportKey::class, TicketsKey::class]);
    $this->bot = $this->makeBot(['bot_owner_peer_id' => ADMIN]);
    $this->admin = $this->grant($this->makeBotUser($this->bot, ADMIN), 'support.manage');
    setting($this->bot, 'support.cooldown_seconds', '0');
});

describe('contact screen', function () {
    it('offers tickets and shows the handles by default', function () {
        setting($this->bot, 'support.handles', '@shop_support');

        $this->say($this->bot, CUSTOMER, __('tbe-support::support.reply_key'));

        expect(sentTo(CUSTOMER)[0])->toContain('@shop_support')->toContain('ticket');
    });

    it('answers /support the same way', function () {
        $this->say($this->bot, CUSTOMER, '/support');

        expect(sentTo(CUSTOMER)[0])->toBe(__('tbe-support::support.main.tickets_only'));
    });

    it('hides the handle block when no handles are set', function () {
        $this->say($this->bot, CUSTOMER, '/support');

        expect(sentTo(CUSTOMER)[0])->not->toContain('urgent');
    });

    it('hides handles in tickets only mode', function () {
        setting($this->bot, 'support.mode', 'tickets');
        setting($this->bot, 'support.handles', '@shop_support');

        $this->say($this->bot, CUSTOMER, '/support');

        expect(sentTo(CUSTOMER)[0])->not->toContain('@shop_support');
    });

    it('escapes what the owner typed', function () {
        setting($this->bot, 'support.handles', 'Sales <b>& more');

        $this->say($this->bot, CUSTOMER, '/support');

        expect(sentTo(CUSTOMER)[0])->toContain('Sales &lt;b&gt;&amp; more');
    });

    it('takes no new tickets in handles only mode', function () {
        setting($this->bot, 'support.mode', 'handles');
        setting($this->bot, 'support.handles', '@shop_support');

        $this->say($this->bot, CUSTOMER, '/support');
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $this->say($this->bot, CUSTOMER, 'hello');

        expect(sentTo(CUSTOMER)[0])->toContain('@shop_support')
            ->and(Ticket::query()->count())->toBe(0);
    });

    it('keeps existing tickets answerable in handles only mode', function () {
        $ticket = $this->openTicket($this->bot);
        setting($this->bot, 'support.mode', 'handles');

        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$ticket->id]);
        $this->say($this->bot, ADMIN, 'still here');
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'reply', [$ticket->id]);
        $this->say($this->bot, CUSTOMER, 'thanks');

        expect($ticket->messages()->count())->toBe(3)
            ->and($ticket->refresh()->status)->toBe(TicketStatus::Open);
    });

    it('does not let a group see it', function () {
        $this->postWebhookUpdate($this->bot, $this->inGroupChat($this->makeMessageUpdate('/support', CUSTOMER)))->assertOk();

        expect(sentTo(-100123456))->not->toContain(__('tbe-support::support.main.tickets_only'));
    });
});

describe('opening a ticket', function () {
    it('saves it, alerts the owner and confirms to the customer', function () {
        $ticket = $this->openTicket($this->bot);

        expect($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->messages()->sole()->text)->toBe('my vpn is down')
            ->and(implode("\n", sentTo(ADMIN)))->toContain('my vpn is down')->toContain('#'.$ticket->id)
            ->and(implode("\n", sentTo(CUSTOMER)))->toContain((string) __('tbe-support::support.answers.ticket_created', ['id' => $ticket->id]));
    });

    it('takes a photo with its caption and keeps the largest size', function () {
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $update = $this->makeMessageUpdate('x', CUSTOMER);
        unset($update['message']['text']);
        $update['message']['caption'] = 'receipt';
        $update['message']['photo'] = [
            ['file_id' => 'small', 'file_unique_id' => 's', 'width' => 90, 'height' => 90],
            ['file_id' => 'AgACAgQAAxkBAAIlarge-photo_id', 'file_unique_id' => 'l', 'width' => 800, 'height' => 800],
        ];
        $this->postWebhookUpdate($this->bot, $update)->assertOk();

        $message = Ticket::query()->sole()->messages()->sole();

        expect($message->kind)->toBe('photo')->and($message->file_id)->toBe('AgACAgQAAxkBAAIlarge-photo_id')->and($message->text)->toBe('receipt');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto') && $request['photo'] === 'AgACAgQAAxkBAAIlarge-photo_id' && (int) $request['chat_id'] === ADMIN);
    });

    it('never cuts an escaped caption mid entity', function () {
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $update = $this->makeMessageUpdate('x', CUSTOMER);
        unset($update['message']['text']);
        $update['message']['caption'] = str_repeat('&', 800);
        $update['message']['document'] = ['file_id' => 'BQACAgQAAxkBAAIdocument-file_id', 'file_unique_id' => 'd'];
        $this->postWebhookUpdate($this->bot, $update)->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendDocument')
            && str_ends_with((string) $request['caption'], str_repeat('&amp;', 800)));
    });

    it('stops at the limit of open tickets', function () {
        setting($this->bot, 'support.max_open_tickets', '1');
        $this->openTicket($this->bot);

        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');

        expect(Ticket::query()->count())->toBe(1);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), (string) __('tbe-support::support.errors.too_many_open', ['limit' => 1])));
    });

    it('refuses a second message inside the cooldown', function () {
        setting($this->bot, 'support.cooldown_seconds', '60');
        $first = $this->openTicket($this->bot);

        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $this->say($this->bot, CUSTOMER, 'again');

        expect(Ticket::query()->count())->toBe(1)
            ->and($first->messages()->count())->toBe(1);
    });

    it('refuses a suspended customer but not support staff', function () {
        $this->makeBotUser($this->bot, CUSTOMER, ['suspend' => true]);

        $this->say($this->bot, CUSTOMER, '/support');
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $this->say($this->bot, CUSTOMER, 'let me in');

        expect(Ticket::query()->count())->toBe(0);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), (string) __('tbe-support::support.errors.suspended')));
    });

    it('still creates the ticket when no one can be reached', function () {
        fakeTelegram([ADMIN]);

        $ticket = $this->openTicket($this->bot);

        expect($ticket->exists)->toBeTrue()
            ->and(implode("\n", sentTo(CUSTOMER)))->toContain('#'.$ticket->id);
    });

    it('splits a long message rather than cutting it', function () {
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'newTicket');
        $this->say($this->bot, CUSTOMER, str_repeat('a', 4000).'END');

        $sent = sentTo(ADMIN);

        expect($sent)->toHaveCount(2)
            ->and($sent[1])->toEndWith('END')
            ->and(TicketLink::query()->where('chat_id', ADMIN)->count())->toBe(2);
    });
});

describe('replying', function () {
    it('delivers a staff reply as Support and hides who sent it', function () {
        $ticket = $this->openTicket($this->bot);

        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$ticket->id]);
        $this->say($this->bot, ADMIN, 'try the new config');

        $toCustomer = implode("\n", sentTo(CUSTOMER));

        expect($ticket->refresh()->status)->toBe(TicketStatus::Answered)
            ->and($ticket->last_staff_id)->toBe($this->admin->id)
            ->and($toCustomer)->toContain('try the new config')
            ->not->toContain($this->admin->telegramUser->first_name);
    });

    it('routes a customer reply back to staff and reopens', function () {
        $ticket = $this->openTicket($this->bot);
        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$ticket->id]);
        $this->say($this->bot, ADMIN, 'answer');

        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'reply', [$ticket->id]);
        $this->say($this->bot, CUSTOMER, 'still broken');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
            ->and(implode("\n", sentTo(ADMIN)))->toContain('still broken');
    });

    it('tells staff when the customer cannot be reached and keeps the reply', function () {
        $ticket = $this->openTicket($this->bot);
        fakeTelegram([CUSTOMER]);

        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$ticket->id]);
        $this->say($this->bot, ADMIN, 'answer');

        expect($ticket->messages()->count())->toBe(2)
            ->and(sentTo(ADMIN))->toContain((string) __('tbe-support::support.answers.not_delivered'));
    });

    it('does not let a customer use someone else\'s ticket', function () {
        $ticket = $this->openTicket($this->bot);

        $this->press($this->bot, 7099, 'SUPPORT', 'reply', [$ticket->id]);
        $this->say($this->bot, 7099, 'sneaky');
        $this->press($this->bot, 7099, 'SUPPORT', 'close', [$ticket->id]);

        expect($ticket->refresh()->messages()->count())->toBe(1)
            ->and($ticket->status)->toBe(TicketStatus::Open);
    });

    it('does not let a customer use staff buttons', function () {
        $ticket = $this->openTicket($this->bot);

        $this->press($this->bot, CUSTOMER, 'TICKETS', 'close', [$ticket->id]);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    });
});

describe('native reply', function () {
    function linkFor(int $chatId): TicketLink
    {
        return TicketLink::query()->where('chat_id', $chatId)->latest('id')->firstOrFail();
    }

    it('answers from staff by swiping on the alert', function () {
        $ticket = $this->openTicket($this->bot);

        $this->say($this->bot, ADMIN, 'swiped answer', ['reply_to_message' => ['message_id' => linkFor(ADMIN)->message_id, 'date' => 1, 'chat' => ['id' => ADMIN, 'type' => 'private']]]);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Answered)
            ->and(implode("\n", sentTo(CUSTOMER)))->toContain('swiped answer');
    });

    it('answers from the customer by swiping on a staff message', function () {
        $ticket = $this->openTicket($this->bot);
        $this->say($this->bot, ADMIN, 'answer', ['reply_to_message' => ['message_id' => linkFor(ADMIN)->message_id, 'date' => 1, 'chat' => ['id' => ADMIN, 'type' => 'private']]]);

        $this->say($this->bot, CUSTOMER, 'thanks', ['reply_to_message' => ['message_id' => linkFor(CUSTOMER)->message_id, 'date' => 1, 'chat' => ['id' => CUSTOMER, 'type' => 'private']]]);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
            ->and(implode("\n", sentTo(ADMIN)))->toContain('thanks');
    });

    it('refuses staff who lost the ability since the alert', function () {
        $ticket = $this->openTicket($this->bot);
        $other = 7003;
        TicketLink::query()->create(['ticket_id' => $ticket->id, 'bot_id' => $this->bot->id, 'chat_id' => $other, 'message_id' => 555, 'side' => 'staff']);

        $this->say($this->bot, $other, 'old alert', ['reply_to_message' => ['message_id' => 555, 'date' => 1, 'chat' => ['id' => $other, 'type' => 'private']]]);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
            ->and($ticket->messages()->count())->toBe(1);
    });

    it('refuses a sticker but keeps the ticket as it was', function () {
        $ticket = $this->openTicket($this->bot);
        $update = $this->makeMessageUpdate('x', ADMIN);
        unset($update['message']['text']);
        $update['message']['sticker'] = ['file_id' => 's', 'file_unique_id' => 's', 'type' => 'regular', 'width' => 1, 'height' => 1, 'is_animated' => false, 'is_video' => false];
        $update['message']['reply_to_message'] = ['message_id' => linkFor(ADMIN)->message_id, 'date' => 1, 'chat' => ['id' => ADMIN, 'type' => 'private']];

        $this->postWebhookUpdate($this->bot, $update)->assertOk();

        expect($ticket->refresh()->messages()->count())->toBe(1);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), (string) __('tbe-support::support.errors.unsupported')));
    });

    it('ignores a reply to an unrelated message', function () {
        $this->openTicket($this->bot);

        $this->say($this->bot, CUSTOMER, 'hm', ['reply_to_message' => ['message_id' => 424242, 'date' => 1, 'chat' => ['id' => CUSTOMER, 'type' => 'private']]]);

        expect(Ticket::query()->sole()->messages()->count())->toBe(1);
    });

    it('does not match another bot\'s link', function () {
        $ticket = $this->openTicket($this->bot);
        $otherBot = $this->makeBot();

        $this->say($otherBot, ADMIN, 'wrong bot', ['reply_to_message' => ['message_id' => linkFor(ADMIN)->message_id, 'date' => 1, 'chat' => ['id' => ADMIN, 'type' => 'private']]]);

        // The webhook left the other bot's tenancy on: count across tenants.
        expect(TicketMessage::query()->withoutGlobalScopes()->where('ticket_id', $ticket->id)->count())->toBe(1);
    });

    it('lets an open form take the message first', function () {
        $ticket = $this->openTicket($this->bot);
        $second = Ticket::query()->create(['bot_id' => $this->bot->id, 'bot_user_id' => $this->admin->id, 'status' => TicketStatus::Open, 'last_message_at' => now()]);

        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$second->id]);
        $this->say($this->bot, ADMIN, 'for the form', ['reply_to_message' => ['message_id' => linkFor(ADMIN)->message_id, 'date' => 1, 'chat' => ['id' => ADMIN, 'type' => 'private']]]);

        expect($second->messages()->count())->toBe(1)->and($ticket->messages()->count())->toBe(1);
    });
});

describe('closing', function () {
    it('lets the customer close, with no alert to staff', function () {
        $ticket = $this->openTicket($this->bot);
        $before = count(sentTo(ADMIN));

        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'close', [$ticket->id]);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
            ->and(sentTo(ADMIN))->toHaveCount($before);
    });

    it('tells the customer once when staff close it', function () {
        $ticket = $this->openTicket($this->bot);

        $this->press($this->bot, ADMIN, 'TICKETS', 'close', [$ticket->id]);
        $this->press($this->bot, ADMIN, 'TICKETS', 'close', [$ticket->id]);

        $notice = (string) __('tbe-support::support.alerts.closed_by_staff', ['id' => $ticket->id]);

        expect(collect(sentTo(CUSTOMER))->filter(fn ($text) => $text === $notice))->toHaveCount(1)
            ->and($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    });

    it('reopens when the customer replies to a closed ticket', function () {
        $ticket = $this->openTicket($this->bot);
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'close', [$ticket->id]);

        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'reply', [$ticket->id]);
        $this->say($this->bot, CUSTOMER, 'back again');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open)->and($ticket->closed_at)->toBeNull();
    });

    it('delivers a late staff reply and marks it answered', function () {
        $ticket = $this->openTicket($this->bot);
        $this->press($this->bot, CUSTOMER, 'SUPPORT', 'close', [$ticket->id]);

        $this->press($this->bot, ADMIN, 'TICKETS', 'reply', [$ticket->id]);
        $this->say($this->bot, ADMIN, 'late answer');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Answered)
            ->and(implode("\n", sentTo(CUSTOMER)))->toContain('late answer');
    });
});

describe('auto close', function () {
    function answered(Bot $bot, int $userId, int $daysAgo): Ticket
    {
        return Ticket::query()->create(['bot_id' => $bot->id, 'bot_user_id' => $userId, 'status' => TicketStatus::Answered, 'last_message_at' => now()->subDays($daysAgo)]);
    }

    it('closes stale answered tickets and tells the customer once', function () {
        $customer = $this->makeBotUser($this->bot, CUSTOMER);
        $stale = answered($this->bot, $customer->id, 8);
        $fresh = answered($this->bot, $customer->id, 2);

        $this->artisan('tbe:support:close-stale')->assertSuccessful();
        $this->artisan('tbe:support:close-stale')->assertSuccessful();

        $notice = (string) __('tbe-support::support.alerts.closed_stale', ['id' => $stale->id, 'days' => 7]);

        expect($stale->refresh()->status)->toBe(TicketStatus::Closed)
            ->and($fresh->refresh()->status)->toBe(TicketStatus::Answered)
            ->and(collect(sentTo(CUSTOMER))->filter(fn ($text) => $text === $notice))->toHaveCount(1);
    });

    it('leaves tickets open when turned off, per bot', function () {
        setting($this->bot, 'support.auto_close_days', '0');
        $customer = $this->makeBotUser($this->bot, CUSTOMER);
        $off = answered($this->bot, $customer->id, 30);

        $otherBot = $this->makeBot();
        $other = answered($otherBot, $this->makeBotUser($otherBot, 7050)->id, 30);

        $this->artisan('tbe:support:close-stale')->assertSuccessful();

        expect($off->refresh()->status)->toBe(TicketStatus::Answered)
            ->and($other->refresh()->status)->toBe(TicketStatus::Closed);
    });
});

describe('admin view', function () {
    it('lists the longest waiting first and counts each tab', function () {
        $customer = $this->makeBotUser($this->bot, CUSTOMER);
        $newer = Ticket::query()->create(['bot_id' => $this->bot->id, 'bot_user_id' => $customer->id, 'status' => TicketStatus::Open, 'last_message_at' => now()]);
        $older = Ticket::query()->create(['bot_id' => $this->bot->id, 'bot_user_id' => $customer->id, 'status' => TicketStatus::Open, 'last_message_at' => now()->subDay()]);

        $this->say($this->bot, ADMIN, __('tbe-support::support.admin.reply_key'));

        Http::assertSent(function ($request) use ($older, $newer) {
            if (! str_contains($request->url(), '/sendMessage') || ! isset($request['reply_markup'])) {
                return false;
            }
            $markup = (string) $request['reply_markup'];

            return str_contains($markup, 'Open (2)') && strpos($markup, '#'.$older->id) < strpos($markup, '#'.$newer->id);
        });
    });

    it('shows who last answered and what the app adds', function () {
        ticketContext()->add(fn ($customer, $ticket) => 'Active services: 2');
        ticketContext()->add(fn ($customer, $ticket) => throw new RuntimeException('a broken block'));
        $ticket = $this->openTicket($this->bot);

        $this->press($this->bot, ADMIN, 'TICKETS', 'open', [$ticket->id]);

        expect(implode("\n", sentTo(ADMIN)))->toContain('Active services: 2')->toContain('my vpn is down');
    });

    it('is closed to users without the ability', function () {
        $this->say($this->bot, CUSTOMER, __('tbe-support::support.admin.reply_key'));

        expect(implode("\n", sentTo(CUSTOMER)))->not->toContain((string) __('tbe-support::support.admin.title'));
    });
});

it('ignores the channel lock', function () {
    setting($this->bot, 'channel_lock.status', '1');
    setting($this->bot, 'channel_lock.channel_id', 'mychannel');

    $this->say($this->bot, CUSTOMER, '/support');

    expect(sentTo(CUSTOMER))->toContain((string) __('tbe-support::support.main.tickets_only'))
        ->not->toContain((string) __('tbe-settings::bot_settings.channel_lock.prompt'));
});
