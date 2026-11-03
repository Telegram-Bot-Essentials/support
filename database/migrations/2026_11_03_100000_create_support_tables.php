<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TelegramBotEssentials\Essence\Models\Bot;
use TelegramBotEssentials\Essence\Models\BotUser;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Bot::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(BotUser::class)->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->foreignIdFor(BotUser::class, 'last_staff_id')->nullable()->constrained('bot_users')->nullOnDelete();
            // An explicit default: without one, MySQL may touch the first TIMESTAMP on every update.
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'status', 'last_message_at']);
            $table->index(['bot_user_id', 'status']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignIdFor(Bot::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(BotUser::class, 'sender_id')->nullable()->constrained('bot_users')->nullOnDelete();
            $table->string('side', 16);
            $table->string('kind', 16);
            $table->text('text')->nullable();
            $table->string('file_id')->nullable();
            $table->timestamps();
        });

        // What each person was sent, so replying to it natively finds the ticket.
        Schema::create('support_ticket_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignIdFor(Bot::class)->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->string('side', 16);
            $table->timestamps();

            $table->unique(['bot_id', 'chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_links');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
