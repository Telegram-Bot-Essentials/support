<x-filament-panels::page>
    @if ($handles = $this->handles())
        <x-filament::section :heading="__('tbe-support::web.contact')" icon="heroicon-o-chat-bubble-left-right" compact>
            {!! nl2br(e($handles)) !!}
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
