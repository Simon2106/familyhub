<?php

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The catalogue: what points are for, and what each thing costs. */
new #[Layout('layouts::app')] class extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    public string $name = '';

    public int $cost = 10;

    public bool $isActive = true;

    public mixed $image = null;

    public bool $allowanceEnabled = false;

    public string $pencePerPoint = '0';

    public function mount(): void
    {
        $household = Household::current();

        $this->allowanceEnabled = $household->allowanceEnabled();
        $this->pencePerPoint = (string) $household->allowancePencePerPoint();
    }

    #[Computed]
    public function rewards(): Collection
    {
        return Reward::where('household_id', Household::current()->id)
            ->orderBy('sort_order')
            ->orderBy('cost')
            ->get();
    }

    public function addReward(): void
    {
        $this->reset(['editingId', 'name', 'cost', 'isActive', 'image']);
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $reward = $this->find($id);

        $this->editingId = $reward->id;
        $this->name = $reward->name;
        $this->cost = $reward->cost;
        $this->isActive = $reward->is_active;
        $this->image = null;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:80',
            'cost' => 'required|integer|min:1|max:100000',
            'image' => 'nullable|file|max:10240|mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif',
        ]);

        $attributes = [
            'household_id' => Household::current()->id,
            'name' => trim($this->name),
            'cost' => $this->cost,
            'is_active' => $this->isActive,
        ];

        if ($this->image) {
            $disk = config('filesystems.default');
            $attributes['image_disk'] = $disk;
            $attributes['image_path'] = $this->image->store('rewards/'.Household::current()->id, $disk);
        }

        $this->editingId
            ? $this->find($this->editingId)->update($attributes)
            : Reward::create($attributes);

        $this->reset(['editingId', 'name', 'cost', 'isActive', 'image']);
        unset($this->rewards);

        $this->dispatch('saved', message: 'Reward saved.');
    }

    public function deleteReward(int $id): void
    {
        // Redemptions keep their own copy of the name and cost, so removing a
        // reward does not rewrite what a child saved up for.
        $this->find($id)->delete();

        $this->reset(['editingId']);
        unset($this->rewards);

        $this->dispatch('saved', message: 'Reward removed.');
    }

    public function saveAllowance(): void
    {
        $this->validate(['pencePerPoint' => 'required|numeric|min:0|max:1000']);

        Household::current()->setAllowance($this->allowanceEnabled, (float) $this->pencePerPoint);

        $this->dispatch('saved', message: 'Allowance saved.');
    }

    protected function find(int $id): Reward
    {
        return Reward::where('household_id', Household::current()->id)->findOrFail($id);
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('admin') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Rewards</h1>
        <button type="button" wire:click="addReward"
                class="touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 space-y-3 px-4 pb-8">
        @if ($editingId !== null)
            <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">{{ $editingId ? 'Edit reward' : 'New reward' }}</h2>

                <div class="flex gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">What</span>
                        <input wire:model="name" type="text" placeholder="An hour of screen time"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    </label>
                    <label class="w-28 shrink-0">
                        <span class="block text-sm font-medium">Points</span>
                        <input wire:model="cost" type="number" inputmode="numeric" min="1"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 text-center dark:border-slate-700 dark:bg-slate-950">
                    </label>
                </div>
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('cost') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="block">
                    <span class="block text-sm font-medium">Picture (optional)</span>
                    <input wire:model="image" type="file" accept="image/*"
                           class="mt-1 w-full rounded-xl border border-slate-300 p-3 dark:border-slate-700 dark:bg-slate-950">
                </label>
                @error('image') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="flex touch-target items-center gap-2">
                    <input wire:model="isActive" type="checkbox" class="size-5 rounded">
                    <span class="text-sm font-medium">Offered</span>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                    <button type="button" wire:click="$set('editingId', null)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    @if ($editingId)
                        <button type="button" wire:click="deleteReward({{ $editingId }})"
                                wire:confirm="Remove this reward? Anything already redeemed is kept."
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                    @endif
                </div>
            </form>
        @endif

        @forelse ($this->rewards as $reward)
            <button type="button" wire:click="edit({{ $reward->id }})" wire:key="reward-{{ $reward->id }}"
                    class="flex w-full touch-target items-center gap-3 rounded-2xl bg-white p-3 text-left dark:bg-slate-900 {{ $reward->is_active ? '' : 'opacity-50' }}">
                @if ($reward->imageUrl())
                    <img src="{{ $reward->imageUrl() }}" alt="" class="size-12 shrink-0 rounded-xl object-cover">
                @else
                    <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl dark:bg-slate-800">🎁</span>
                @endif
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $reward->name }}</span>
                    @unless ($reward->is_active)
                        <span class="block text-sm text-slate-400">Not offered</span>
                    @endunless
                </span>
                <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-bold tabular-nums dark:bg-slate-800">{{ $reward->cost }}</span>
            </button>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-slate-200 p-10 text-center dark:border-slate-800">
                <p class="font-semibold text-slate-400">Nothing to save up for yet.</p>
                <p class="mt-1 text-sm text-slate-400">Points only mean something once there is something to spend them on.</p>
            </div>
        @endforelse

        {{-- Allowance --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Pocket money</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Off by default. Turned on, points are also shown as money, and the weekly
                summary totals what each child earned.
            </p>

            <label class="mt-3 flex touch-target items-center gap-2">
                <input wire:model.live="allowanceEnabled" type="checkbox" class="size-5 rounded">
                <span class="text-sm font-medium">Points are worth money</span>
            </label>

            @if ($allowanceEnabled)
                <label class="mt-2 flex items-center gap-2">
                    <input wire:model="pencePerPoint" type="number" inputmode="decimal" step="0.5" min="0"
                           aria-label="Pence per point"
                           class="touch-target w-24 rounded-xl border border-slate-300 px-3 text-center dark:border-slate-700 dark:bg-slate-950">
                    <span class="text-sm text-slate-500 dark:text-slate-400">pence per point</span>
                </label>
                @error('pencePerPoint') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @endif

            <button type="button" wire:click="saveAllowance"
                    class="mt-3 w-full touch-target rounded-xl bg-blue-600 font-semibold text-white">Save</button>
        </section>
    </div>
</div>
