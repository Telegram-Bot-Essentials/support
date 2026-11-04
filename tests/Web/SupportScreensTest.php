<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Essence\Web\Filament\PanelUser;
use TelegramBotEssentials\Settings\Models\BotSetting;
use TelegramBotEssentials\Support\Enums\TicketSide;
use TelegramBotEssentials\Support\Enums\TicketStatus;
use TelegramBotEssentials\Support\Models\Ticket;
use TelegramBotEssentials\Support\Web\Customer\TicketPage;
use TelegramBotEssentials\Support\Web\Customer\TicketsPage;
use TelegramBotEssentials\Support\Web\Manage\ListTickets;
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

describe('customer screens', function () {
    it('lists only the customer\'s own tickets', function () {
        $mine = ticketFor($this->customer);
        $theirs = ticketFor($this->other);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketsPage::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    });

    it('does not open another customer\'s ticket', function () {
        $theirs = ticketFor($this->other, 'private words');

        $this->enterPanel('customer', $this->bot, $this->customer);

        $this->get('/app/'.$this->bot->unique_id.'/support/'.$theirs->id)->assertNotFound();
    });

    it('opens a ticket through the service, so staff are alerted as from the bot', function () {
        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketsPage::class)
            ->callAction('newTicket', ['message' => 'help from the web'])
            ->assertHasNoActionErrors();

        $ticket = Ticket::query()->where('bot_user_id', $this->customer->id)->sole();
        expect($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->messages()->sole()->text)->toBe('help from the web');
        Http::assertSent(fn ($r) => str_contains((string) $r->url(), '/sendMessage') && (int) $r['chat_id'] === WEB_STAFF);
    });

    it('shows the limit as a message and opens nothing', function () {
        BotSetting::query()->updateOrCreate(['bot_id' => $this->bot->id, 'key' => 'support.max_open_tickets'], ['value' => '1']);
        ticketFor($this->customer);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketsPage::class)
            ->callAction('newTicket', ['message' => 'one more'])
            ->assertNotified();

        expect(Ticket::query()->where('bot_user_id', $this->customer->id)->count())->toBe(1);
    });

    it('hides new tickets in handles-only mode and shows the handles', function () {
        BotSetting::query()->updateOrCreate(['bot_id' => $this->bot->id, 'key' => 'support.mode'], ['value' => 'handles']);
        BotSetting::query()->updateOrCreate(['bot_id' => $this->bot->id, 'key' => 'support.handles'], ['value' => '@our_support']);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketsPage::class)
            ->assertActionHidden('newTicket')
            ->assertSee('@our_support');
    });

    it('replies and reopens a closed ticket, and closes it', function () {
        $ticket = ticketFor($this->customer);
        $ticket->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketPage::class, ['ticket' => $ticket->id])
            ->fillForm(['reply' => 'still broken'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSee('still broken');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Open);

        Livewire::test(TicketPage::class, ['ticket' => $ticket->id])->callAction('close');

        expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    });

    it('shows staff as the team, never by name', function () {
        $ticket = ticketFor($this->customer);
        $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Staff, 'kind' => 'text', 'text' => 'hello', 'sender_id' => $this->staff->id]);
        $this->staff->telegramUser->update(['first_name' => 'SecretStaffName']);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketPage::class, ['ticket' => $ticket->id])
            ->assertSee((string) __('tbe-support::support.history.support'))
            ->assertDontSee('SecretStaffName');
    });

    it('refuses a suspended customer', function () {
        $this->customer->update(['suspended_at' => now()]);
        $this->enterPanel('customer', $this->bot, $this->customer);

        $this->get('/app/'.$this->bot->unique_id.'/support')->assertForbidden();
    });
});

describe('attachments', function () {
    it('survives a real Livewire request on the customer ticket screen', function () {
        $ticket = ticketFor($this->customer);
        $message = $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->customer->id]);

        Auth::guard('tbe-web')->login(PanelUser::find($this->customer->id));

        realLivewireCall($this, '/app/'.$this->bot->unique_id.'/support/'.$ticket->id, 'ticketId', 'downloadAttachment', [$message->id])->assertOk();
    });

    it('proxies the file without ever exposing the bot token', function () {
        $ticket = ticketFor($this->customer);
        $message = $ticket->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->customer->id]);

        $this->enterPanel('customer', $this->bot, $this->customer);

        $component = Livewire::test(TicketPage::class, ['ticket' => $ticket->id])->call('downloadAttachment', $message->id);

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

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketPage::class, ['ticket' => $ticket->id])
            ->call('downloadAttachment', $message->id)
            ->assertHasNoErrors()
            ->assertNotified((string) __('tbe-support::web.file_unavailable'));
    });

    it('does not hand over a file from someone else\'s ticket', function () {
        $mine = ticketFor($this->customer);
        $theirs = ticketFor($this->other);
        $message = $theirs->messages()->create(['bot_id' => $this->bot->id, 'side' => TicketSide::Customer, 'kind' => 'photo', 'file_id' => 'F1', 'sender_id' => $this->other->id]);

        $this->enterPanel('customer', $this->bot, $this->customer);

        Livewire::test(TicketPage::class, ['ticket' => $mine->id])
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

        Auth::guard('tbe-web')->login(PanelUser::find($this->staff->id));

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
