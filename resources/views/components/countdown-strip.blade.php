@props(['entries', 'compact' => false])

{{-- The days until the things worth counting.

     Words rather than a number beside a label: "12 days until Cornwall" is
     what somebody says out loud, and a bare 12 is something to be decoded. --}}
@if ($entries->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
        @foreach ($entries as $entry)
            <span class="flex min-w-0 items-center gap-2 rounded-xl px-3 py-1 {{ $entry->isToday() ? 'text-white' : 'bg-white dark:bg-slate-900' }}"
                  @style(["background-color: {$entry->colour()}" => $entry->isToday()])
                  wire:key="cd-{{ $entry->key }}">
                @if (! $entry->isToday())
                    <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $entry->colour() }};"></span>
                @endif

                <span class="truncate {{ $compact ? 'text-sm' : 'text-base' }} font-semibold">
                    {{ $entry->sentence() }}
                </span>
            </span>
        @endforeach
    </div>
@endif
