# telegram-bot-essentials/support

Lets a bot's customers reach its staff: tickets inside the bot, contact handles to message directly, or both. Built for [telegram-bot-essentials/essence](https://github.com/Telegram-Bot-Essentials/essence).

## Install

```bash
composer require telegram-bot-essentials/support
php artisan migrate
```

List the reply keys in `config/tbe-essence.php`, since essence does not discover a package's keys:

```php
use TelegramBotEssentials\Support\Telegram\ReplyKeys\Admin\TicketsKey;
use TelegramBotEssentials\Support\Telegram\ReplyKeys\Member\SupportKey;

'menus' => [
    'main' => [[SupportKey::class]],
    'admin' => [[TicketsKey::class]],
],
```

`/support` registers itself. Run the scheduler so answered tickets close on their own.

## What it does

**Customers** open the contact screen from the key or `/support`, in a private chat. They can open a ticket with text, a photo or a document, then reply with a button or by replying to a message natively. Staff reach them as "Support". The channel lock does not apply, and suspended users are refused.

**Staff** hold the `support.manage` ability. They get tabs for open, answered and closed tickets, longest waiting first, and reply the same two ways. The ticket pool is shared: there is no claiming.

## Settings

Under **Support** in the bot settings:

| Setting | Default | Meaning |
|---|---|---|
| `support.mode` | `both` | `both`, `tickets` or `handles`. Existing tickets stay answerable in every mode. |
| `support.handles` | empty | Shown as written, such as `Sales: @shop_sales`. Hidden when empty. |
| `support.alert_targets` | owner | The owner and any recipient groups. Falls back to the owner if nobody is reached. |
| `support.max_open_tickets` | 3 | Unclosed tickets per customer. 0 means no limit. |
| `support.cooldown_seconds` | 10 | Between one customer's messages. 0 turns it off. |
| `support.auto_close_days` | 7 | Closes answered tickets nobody came back to. 0 turns it off. |

Recipient groups need a package that provides them, such as `telegram-bot-essentials/access`.

## Adding to a ticket

An app can show facts about the customer in the staff's view. Return HTML, escaped, or null for nothing:

```php
ticketContext()->add(fn (BotUser $customer, Ticket $ticket) => 'Plan: '.e($customer->plan));
```

A block that throws is reported and left out.
