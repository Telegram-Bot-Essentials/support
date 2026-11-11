<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Settings\Models\BotSetting;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Web\Manage\ListTickets;
use TelegramBotEssentials\Support\Web\Manage\TicketResource;
use TelegramBotEssentials\Support\Web\Manage\ViewTicket;

/** Clicks through the real route and middleware, as a browser does. Livewire::test() skips them. */
function realLivewireCall(object $test, string $url, string $stateKey, string $method, array $params = []): TestResponse
{
    $html = $test->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
    $snapshot = collect($m[1])->map(fn ($s) => html_entity_decode($s))->first(fn ($s) => str_contains($s, $stateKey));
    expect($snapshot)->not->toBeNull();

    tenancy()->end();
    app()->forgetScopedInstances();

    return $test->withHeader('X-Livewire', '1')
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->postJson(app('livewire')->getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => new stdClass, 'calls' => [['path' => '', 'method' => $method, 'params' => $params, 'metadata' => []]]]],
        ]);
}

const WEB_CUSTOMER = 8001;
const WEB_OTHER = 8002;
const WEB_STAFF = 8003;

function telegramAnswers(): void
{
    $next = 1000;
    $factory = new Factory;
    $factory->fake(function ($request) use (&$next) {
        $url = (string) $request->url();

        if (str_contains($url, '/file/bot')) {
            return Http::response('FILE-BYTES');
        }

        if (str_contains($url, '/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_id' => 'F1', 'file_unique_id' => 'U1', 'file_path' => 'photos/a.jpg']]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => $next++, 'date' => now()->timestamp, 'chat' => ['id' => (int) ($request['chat_id'] ?? 1), 'type' => 'private']]]);
    });
    Http::swap($factory);
}

function ticketFor(BotUser $customer, string $text = 'my vpn is down'): Ticket
{
    $ticket = Ticket::query()->create(['bot_id' => $customer->getAttribute('bot_id'), 'bot_user_id' => $customer->getKey(), 'status' => TicketStatus::Open, 'last_message_at' => now()]);
    $ticket->messages()->create(['bot_id' => $customer->getAttribute('bot_id'), 'side' => TicketSide::Customer, 'kind' => 'text', 'text' => $text, 'sender_id' => $customer->getKey()]);

    return $ticket;
}

beforeEach(function () {
    telegramAnswers();
    $this->bot = $this->makeBot(['bot_owner_peer_id' => WEB_STAFF]);
    $this->customer = $this->makeBotUser($this->bot, WEB_CUSTOMER);
    $this->other = $this->makeBotUser($this->bot, WEB_OTHER);
    $this->staff = $this->grant($this->makeBotUser($this->bot, WEB_STAFF), 'support.manage', 'web.manage');
    BotSetting::query()->updateOrCreate(['bot_id' => $this->bot->id, 'key' => 'support.cooldown_seconds'], ['value' => '0']);
});

describe('attachments', function () {
    it('proxies the file without ever exposing the bot token', function () {
        $ticket = ticketFor($this->customer);
        $message = $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->customer->id]);

        $this->enterPanel('manage', $this->bot, $this->staff);

        $component = Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])->call('downloadAttachment', $message->id);

        $component->assertFileDownloaded();
        Http::assertSent(fn ($r) => str_contains((string) $r->url(), '/file/bot') && str_ends_with((string) $r->url(), 'photos/a.jpg'));
        expect($component->html())->not->toContain((string) $this->bot->getAttribute('bot_token'));
    });

    it('says so when Telegram will not give the file', function () {
        $factory = new Factory;
        $factory->fake(fn () => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: file is too big'], 400));
        Http::swap($factory);

        $ticket = ticketFor($this->customer);
        $message = $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->customer->id]);

        $this->enterPanel('manage', $this->bot, $this->staff);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->call('downloadAttachment', $message->id)
            ->assertHasNoErrors()
            ->assertNotified((string) __('tbe-support::web.file_unavailable'));
    });

    it('does not hand over a file from someone else\'s ticket', function () {
        $mine = ticketFor($this->customer);
        $theirs = ticketFor($this->other);
        $message = $theirs->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->other->id]);

        $this->enterPanel('manage', $this->bot, $this->staff);

        Livewire::test(ViewTicket::class, ['record' => $mine->getKey()])
            ->call('downloadAttachment', $message->id)
            ->assertStatus(404);
    });
});

describe('manage screens', function () {
    it('keeps ticket screens from anyone without support.manage', function () {
        ticketFor($this->customer);
        $limited = $this->grant($this->makeBotUser($this->bot, 8004), 'web.manage');

        $this->enterPanel('manage', $this->bot, $limited);

        $this->get('/manage/'.$this->bot->unique_id.'/tickets')->assertForbidden();
    });

    it('lists the tickets of this bot only, waiting longest first', function () {
        $older = ticketFor($this->customer);
        $older->update(['last_message_at' => now()->subDay()]);
        $newer = ticketFor($this->other);

        $otherBot = $this->makeBot(['unique_id' => 'other-bot']);
        $foreign = ticketFor($this->makeBotUser($otherBot, 8100));

        $this->enterPanel('manage', $this->bot, $this->staff);

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
            ->assertCanNotSeeTableRecords([$foreign]);
    });

    it('replies as staff, marks it answered and delivers to the customer', function () {
        $ticket = ticketFor($this->customer);

        $this->enterPanel('manage', $this->bot, $this->staff);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])
            ->fillForm(['reply' => 'try the new link'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($ticket->refresh()->status)->toBe(TicketStatus::Answered)
            ->and($ticket->last_staff_id)->toBe($this->staff->id);
        Http::assertSent(fn ($r) => str_contains((string) $r->url(), '/sendMessage') && (int) $r['chat_id'] === WEB_CUSTOMER);
    });

    it('survives a real Livewire request on the ticket screen', function () {
        $ticket = ticketFor($this->customer);
        $message = $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->customer->id]);

        Auth::guard('tbe-web')->login($this->panelAccount($this->staff));

        realLivewireCall($this, '/manage/'.$this->bot->unique_id.'/tickets/'.$ticket->id, 'ViewTicket', 'downloadAttachment', [$message->id])->assertOk();
    });

    it('closes a ticket and tells the customer', function () {
        $ticket = ticketFor($this->customer);

        $this->enterPanel('manage', $this->bot, $this->staff);

        Livewire::test(ViewTicket::class, ['record' => $ticket->getKey()])->callAction('close');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
        Http::assertSent(fn ($r) => str_contains((string) $r->url(), '/sendMessage') && (int) $r['chat_id'] === WEB_CUSTOMER);
    });
});

describe('sidebar badge', function () {
    it('counts open tickets of this bot only', function () {
        ticketFor($this->customer);
        ticketFor($this->other)->update(['status' => TicketStatus::Answered]);
        $otherBot = $this->makeBot(['unique_id' => 'other-support-bot']);
        ticketFor($this->makeBotUser($otherBot, 8100));

        $this->enterPanel('manage', $this->bot, $this->staff);

        expect(TicketResource::getNavigationBadge())->toBe('1');
    });

    it('shows nothing to who may not handle tickets', function () {
        ticketFor($this->customer);
        $this->enterPanel('manage', $this->bot, $this->grant($this->makeBotUser($this->bot, 8004), 'web.manage'));

        expect(TicketResource::getNavigationBadge())->toBeNull();
    });
});
