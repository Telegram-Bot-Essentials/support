<?php

namespace TelegramBotEssentials\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\Enums\TicketSide;

/**
 * @property int $id
 * @property int $ticket_id
 * @property TicketSide $side
 * @property string $kind
 * @property string|null $text
 * @property string|null $file_id
 * @property Carbon|null $created_at
 * @property-read BotUser|null $sender
 */
class TicketMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'support_ticket_messages';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = ['side' => TicketSide::class];

    /** @return BelongsTo<BotUser, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'sender_id');
    }
}
