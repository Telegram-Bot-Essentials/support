<x-filament-panels::page>
    <x-filament::section :heading="__('tbe-support::web.customer')" icon="heroicon-o-user" compact>
        <x-tbe::facts>
            <x-tbe::fact :label="__('tbe-support::web.customer')" :value="$this->customerName()" />
            <x-tbe::fact label="@" :value="$this->customerUsername()" />
            <x-tbe::fact label="ID"><code style="user-select:all;">{{ $this->customerPeerId() }}</code></x-tbe::fact>
        </x-tbe::facts>
        @if (filled($context = $this->contextHtml()))
            <div style="margin-top:.75rem;">{!! nl2br($context) !!}</div>
        @endif
    </x-filament::section>

    @foreach ($this->threadMessages() as $message)
        <x-filament::section
            :heading="$this->authorOf($message)"
            :description="\TelegramBotEssentials\Essence\Web\PanelCalendar::format($message->created_at)"
            :icon="$message->side === \TelegramBotEssentials\Support\Enums\TicketSide::Staff ? 'heroicon-o-lifebuoy' : 'heroicon-o-user'"
            compact
            wire:key="message-{{ $message->id }}"
        >
            @if (filled($message->text))
                <div>{!! nl2br(e($message->text)) !!}</div>
            @endif

            @if ($message->file_id)
                <div style="margin-block-start: .5rem">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-paper-clip" wire:click="downloadAttachment({{ $message->id }})">
                        {{ __('tbe-support::web.attachment') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endforeach

    <form wire:submit="send">
        {{ $this->form }}

        <div style="margin-block-start: 1rem">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                {{ __('tbe-support::web.send') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
