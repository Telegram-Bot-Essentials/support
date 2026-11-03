<?php

return [
    'reply_key' => '💬 Contact us',
    'command' => 'Contact support',

    'main' => [
        'tickets_and_handles' => "💬 <b>Contact us</b>\n\nOpen a ticket and we'll answer you here.\n\nFor something urgent:\n:handles",
        'tickets_only' => "💬 <b>Contact us</b>\n\nOpen a ticket and we'll answer you here.",
        'handles_only' => "💬 <b>Contact us</b>\n\n:handles",
        'nothing' => 'Contact details are not available right now. Please try again later.',
    ],

    'status' => [
        'open' => 'Open',
        'answered' => 'Answered',
        'closed' => 'Closed',
    ],

    'keys' => [
        'new_ticket' => '✉️ New ticket',
        'my_tickets' => '🗂 My tickets',
        'reply' => '✍️ Reply',
        'open' => '📂 Open ticket',
        'close' => '✅ Close ticket',
        'attachments' => '📎 Show attachments',
        'profile' => '👤 Customer profile',
        'back' => '⬅️ Back',
        'previous' => '◀️ Previous',
        'next' => 'Next ▶️',
        'cancel' => '❌ Cancel',
    ],

    'tickets' => [
        'title' => 'Your tickets:',
        'empty' => 'You have no tickets yet.',
        'row' => '#:id · :status · :date',
        'show' => '<b>Ticket #:id</b> · :status',
    ],

    'history' => [
        'you' => 'You',
        'customer' => 'Customer',
        'support' => 'Support',
    ],

    'prompts' => [
        'new_ticket' => 'Send your message. Text, a photo or a document is fine.',
        'reply' => 'Send your reply. Text, a photo or a document is fine.',
    ],

    'answers' => [
        'ticket_created' => '✅ Ticket #:id is open. We will answer you here.',
        'sent' => '✅ Sent.',
        'not_delivered' => '⚠️ Saved, but not delivered: the customer cannot be reached right now.',
        'cancelled' => 'Cancelled.',
    ],

    'alerts' => [
        'new_ticket' => '🎫 <b>New ticket #:id</b> from :name',
        'customer_replied' => '💬 <b>Ticket #:id</b>: :name replied',
        'staff_replied' => '💬 <b>Support replied on ticket #:id</b>',
        'closed_by_staff' => '✅ Ticket #:id was closed. Reply to it any time to open it again.',
        'closed_stale' => '✅ Ticket #:id was closed after :days days without a reply. Reply to it any time to open it again.',
    ],

    'errors' => [
        'suspended' => 'Your account is suspended, so you cannot contact support here.',
        'tickets_off' => 'Tickets are not available right now.',
        'too_many_open' => 'You already have :limit open tickets. Please close one or wait for an answer.',
        'too_soon' => 'Please wait :seconds seconds between messages.',
        'unsupported' => 'Only text, a photo or a document can be sent.',
    ],

    'admin' => [
        'reply_key' => '🎫 Tickets',
        'title' => 'Support tickets:',
        'empty' => 'No tickets here.',
        'row' => '#:id · :name · :date',
        'show' => "<b>Ticket #:id</b> · :status\nCustomer: :name (:username)\nID: <code>:peer</code>\nLast answered by: :staff",
        'reply_prompt' => 'Send your reply to ticket #:id. Text, a photo or a document is fine. The customer sees it as coming from Support.',
    ],

    'settings' => [
        'label' => 'Support',
        'description' => 'How customers contact you.',
        'mode' => 'Contact options',
        'mode_description' => 'What customers are offered. Tickets already open stay answerable whichever you pick.',
        'mode_both' => 'Tickets and handles',
        'mode_tickets' => 'Tickets only',
        'mode_handles' => 'Handles only',
        'handles' => 'Contact handles',
        'handles_description' => 'Shown to customers as written, for example "Sales: @shop_sales". Prefer a dedicated support account over a personal one. Leave empty to show none.',
        'alert_targets' => 'Who is told about tickets',
        'alert_targets_description' => 'Who hears about new tickets and customer replies. When this reaches nobody, the owner is told.',
        'alert_targets_owner' => 'Bot owner',
        'max_open_tickets' => 'Open tickets per customer',
        'max_open_tickets_description' => 'How many unclosed tickets one customer can have. 0 means no limit.',
        'cooldown_seconds' => 'Seconds between customer messages',
        'cooldown_seconds_description' => 'Stops a customer flooding the staff. 0 turns this off.',
        'auto_close_days' => 'Close answered tickets after (days)',
        'auto_close_days_description' => 'Closes an answered ticket the customer has not come back to, and tells them once. 0 turns this off.',
    ],
];
