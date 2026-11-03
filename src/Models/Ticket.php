<?php

namespace TelegramBotEssentials\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;
use TelegramBotEssentials\Support\Enums\TicketStatus;

/**
 * @property int $id
 * @property int $bot_id
 * @property int $bot_user_id
 * @property TicketStatus $status
 * @property int|null $last_staff_id
 * @property Carbon $last_message_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property-read BotUser $customer
 * @property-read BotUser|null $lastStaff
 * @property-read Bot $bot
 */
class Ticket extends Model
{
    use BelongsToTenant;

    protected $table = 'support_tickets';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'status' => TicketStatus::class,
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<BotUser, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }

    /** @return BelongsTo<BotUser, $this> */
    public function lastStaff(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'last_staff_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->where('status', '!=', TicketStatus::Closed->value);
    }
}
