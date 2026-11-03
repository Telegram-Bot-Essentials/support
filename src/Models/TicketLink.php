<?php

namespace TelegramBotEssentials\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use TelegramBotEssentials\Support\Enums\TicketSide;

/**
 * A message the bot sent someone about a ticket, so replying to it reaches that ticket.
 *
 * @property int $ticket_id
 * @property TicketSide $side
 * @property-read Ticket $ticket
 */
class TicketLink extends Model
{
    use BelongsToTenant;

    protected $table = 'support_ticket_links';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = ['side' => TicketSide::class];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
