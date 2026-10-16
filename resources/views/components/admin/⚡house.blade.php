<?php

use App\Models\ActivityEvent;
use App\Models\Household;
use App\Models\Member;
use App\Services\Announce\Announcer;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\Presence;
use App\Services\HomeAssistant\WhosHome;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What the wall knows about the house.
 *
 * Four features that all need the same thing: somebody choosing entities.
 * None of them is guessable — an mmWave sensor's zones are named by whoever
 * drew them, an Alexa notify service is named after the account — so all of
 * it is picked here rather than hard-coded, and everything degrades to
 * nothing at all when it is left blank.
 */
new #[Layout('layouts::app')] class extends Component
{
    /* --------------------------- proximity --------------------------- */

    public string $nearZone = '';

    public string $roomZone = '';

    public string $luxSensor = '';

    public int $deepSleepMinutes = 10;

    public string $simulate = '';

    /* --------------------------- who is home -------------------------- */

    /** @var array<int, string> member id to entity */
    public array $presence = [];

    /* ------------------------- the activity feed ---------------------- */

    /** @var array<string, array{label: string, category: string}> */
    public array $watching = [];

    public string $newEntity = '';

    public string $newLabel = '';

    public string $newCategory = 'other';

    public bool $quietEnabled = false;

    public string $quietStart = '22:00';

    public string $quietEnd = '07:00';

    /* ---------------------------- announce ---------------------------- */

    /** @var list<string> */
    public array $presets = [];

    public string $announceService = '';

    /** @var list<string> */
    public array $announceTargets = [];

    public string $newTarget = '';

    public ?string $saved = null;

    /** Why the notify services could not be listed, if they could not. */
    public ?string $serviceError = null;

    public function mount(): void
    {
        $household = $this->household();

        $zones = $household->presenceZones();
        $this->nearZone = (string) $zones['near'];
        $this->roomZone = (string) $zones['room'];
        $this->luxSensor = (string) $zones['lux'];
        $this->deepSleepMinutes = $household->deepSleepMinutes();
        $this->simulate = (string) $household->simulatedPresence();

        $this->presence = $household->presenceEntities();
        $this->watching = $household->activityEntities();

        $quiet = $household->activityQuietHours();
        $this->quietEnabled = $quiet !== null;
        $this->quietStart = $quiet['start'] ?? '22:00';
        $this->quietEnd = $quiet['end'] ?? '07:00';

        $this->presets = $household->announcePresets();

        $announce = $household->announceConfig();
        $this->announceService = (string) $announce['service'];
        $this->announceTargets = $announce['targets'];
    }

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function connected(): bool
    {
        return app(HomeAssistant::class)->isConfigured();
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
    }

    /**
     * What Home Assistant offers, for each picker.
     *
     * Empty when HA cannot be reached, which turns every picker into a
     * plain text field — somebody who knows the entity id can still type
     * it, and a page that refuses to load because a Pi is off is worse
     * than one that asks for typing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function choices(array $domains): Collection
    {
        if (! $this->connected) {
            return collect();
        }

        try {
            return app(HomeAssistant::class)->choices($domains);
        } catch (\Throwable) {
            return collect();
        }
    }

    #[Computed]
    public function sensors(): Collection
    {
        return $this->choices(['binary_sensor']);
    }

    #[Computed]
    public function numbers(): Collection
    {
        return $this->choices(['sensor']);
    }

    #[Computed]
    public function people(): Collection
    {
        return $this->choices(['person', 'device_tracker']);
    }

    #[Computed]
    public function anything(): Collection
    {
        return $this->choices(['binary_sensor', 'sensor', 'person', 'device_tracker', 'climate', 'light', 'switch', 'lock', 'cover']);
    }

    /* ---------------------------- the state ---------------------------- */

    /**
     * The notify services HA offers, for the picker.
     *
     * Empty when HA cannot be reached, which turns the picker back into a
     * text field — somebody who knows the service name can still type it,
     * and a settings page that will not load because a Pi is off is worse
     * than one that asks for typing.
     *
     * @return Collection<int, array{service: string, name: string}>
     */
    #[Computed]
    public function notifyServices(): Collection
    {
        if (! $this->connected) {
            return collect();
        }

        try {
            return app(HomeAssistant::class)->notifyServices();
        } catch (\Throwable $e) {
            $this->serviceError = $e->getMessage();

            return collect();
        }
    }

    #[Computed]
    public function presenceState(): array
    {
        return app(Presence::class)->explain($this->household());
    }

    #[Computed]
    public function homeState(): array
    {
        return app(WhosHome::class)->explain($this->household());
    }

    #[Computed]
    public function announcements(): array
    {
        return app(Announcer::class)->recent();
    }

    #[Computed]
    public function speaks(): bool
    {
        return app(Announcer::class)->speaks($this->household());
    }

    #[Computed]
    public function recentActivity(): Collection
    {
        return ActivityEvent::query()
            ->forHousehold($this->household())
            ->orderByDesc('at')
            ->limit(10)
            ->get();
    }

    /* ----------------------------- saving ------------------------------ */

    public function saveProximity(): void
    {
        $this->validate(['deepSleepMinutes' => 'required|integer|min:0|max:240']);

        $household = $this->household();
        $household->setPresenceZones($this->nearZone, $this->roomZone, $this->luxSensor);
        $household->setDeepSleepMinutes($this->deepSleepMinutes);
        $household->setSimulatedPresence($this->simulate ?: null);

        unset($this->presenceState);

        $this->saved = 'Proximity saved.';
    }

    public function savePresence(): void
    {
        $this->household()->setPresenceEntities($this->presence);

        unset($this->homeState);

        $this->saved = 'Who is home saved.';
    }

    public function watch(): void
    {
        $this->validate([
            'newEntity' => 'required|string|max:120',
            'newLabel' => 'nullable|string|max:60',
        ]);

        $this->watching[$this->newEntity] = [
            'label' => $this->newLabel ?: $this->newEntity,
            'category' => $this->newCategory,
        ];

        $this->household()->setActivityEntities($this->watching);

        $this->reset(['newEntity', 'newLabel']);
        $this->newCategory = 'other';

        $this->saved = 'Added to the feed.';
    }

    public function unwatch(string $entityId): void
    {
        unset($this->watching[$entityId]);

        $this->household()->setActivityEntities($this->watching);

        $this->saved = 'Removed from the feed.';
    }

    public function saveQuiet(): void
    {
        $this->validate([
            'quietStart' => 'required|date_format:H:i',
            'quietEnd' => 'required|date_format:H:i',
        ]);

        $this->household()->setActivityQuietHours(
            $this->quietEnabled ? $this->quietStart : null,
            $this->quietEnabled ? $this->quietEnd : null,
        );

        $this->saved = 'Quiet hours saved.';
    }

    public function addTarget(): void
    {
        if (filled($this->newTarget)) {
            $this->announceTargets[] = trim($this->newTarget);
            $this->newTarget = '';
        }
    }

    public function removeTarget(int $at): void
    {
        unset($this->announceTargets[$at]);

        $this->announceTargets = array_values($this->announceTargets);
    }

    public function saveAnnounce(): void
    {
        $household = $this->household();
        $household->setAnnouncePresets($this->presets);
        $household->setAnnounceConfig($this->announceService, $this->announceTargets);

        unset($this->speaks, $this->notifyServices);

        $this->saved = 'Announcements saved.';
    }

    public function testAnnounce(): void
    {
        $result = app(Announcer::class)->announce($this->household(), 'This is a test from FamilyHub.');

        unset($this->announcements);

        $this->saved = $result['spoken']
            ? 'Said out loud.'
            : ($result['reason'] ?? 'Shown on the wall only.');
    }
}; ?>

@php
    $field = 'touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950';
    $note = 'mt-1 block text-sm text-slate-500 dark:text-slate-400';
@endphp

<div class="mx-auto max-w-3xl px-4 pb-16">
    <header class="flex items-center gap-3 py-4">
        <a href="{{ route('admin') }}" wire:navigate
           class="grid touch-target shrink-0 place-items-center rounded-xl bg-white px-3 dark:bg-slate-900"
           aria-label="Back to settings">
            <x-icon name="chevron-left" class="size-6" />
        </a>
        <h1 class="text-2xl font-bold">The house</h1>
    </header>

    @if ($saved)
        <p class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100">
            {{ $saved }}
        </p>
    @endif

    @unless ($this->connected)
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-100">
            Home Assistant is not connected — set HA_URL and HA_TOKEN. Everything below can
            still be filled in by hand, and none of it will do anything until it is.
        </p>
    @endunless

    {{-- ========================== PROXIMITY ========================== --}}
    <section class="mb-6 rounded-2xl bg-white p-4 dark:bg-slate-900">
        <h2 class="font-semibold">Waking when somebody walks up</h2>
        <p class="{{ $note }}">
            An mmWave sensor exposes one binary sensor per zone. The wall wakes for the near
            zone, shows the screensaver while somebody is merely in the room, and goes dark
            when the room has been empty a while.
        </p>

        <label class="mt-3 block">
            <span class="block text-sm font-medium">Near zone</span>
            @if ($this->sensors->isNotEmpty())
                <select wire:model="nearZone" class="{{ $field }}">
                    <option value="">Not set</option>
                    @foreach ($this->sensors as $entity)
                        <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }} — {{ $entity['entity_id'] }}</option>
                    @endforeach
                </select>
            @else
                <input wire:model="nearZone" type="text" placeholder="binary_sensor.fp2_near" class="{{ $field }}">
            @endif
        </label>

        <label class="mt-3 block">
            <span class="block text-sm font-medium">Room zone</span>
            @if ($this->sensors->isNotEmpty())
                <select wire:model="roomZone" class="{{ $field }}">
                    <option value="">Not set</option>
                    @foreach ($this->sensors as $entity)
                        <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }} — {{ $entity['entity_id'] }}</option>
                    @endforeach
                </select>
            @else
                <input wire:model="roomZone" type="text" placeholder="binary_sensor.fp2_room" class="{{ $field }}">
            @endif
        </label>

        <label class="mt-3 block">
            <span class="block text-sm font-medium">Light level (optional)</span>
            @if ($this->numbers->isNotEmpty())
                <select wire:model="luxSensor" class="{{ $field }}">
                    <option value="">Not set</option>
                    @foreach ($this->numbers as $entity)
                        <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }} — {{ $entity['entity_id'] }}</option>
                    @endforeach
                </select>
            @else
                <input wire:model="luxSensor" type="text" placeholder="sensor.fp2_light_level" class="{{ $field }}">
            @endif
        </label>

        <label class="mt-3 block">
            <span class="block text-sm font-medium">Go dark after</span>
            <input wire:model="deepSleepMinutes" type="number" min="0" max="240" class="{{ $field }} w-28">
            <span class="{{ $note }}">Minutes of an empty room. 0 never goes dark.</span>
        </label>
        @error('deepSleepMinutes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

        <label class="mt-3 block">
            <span class="block text-sm font-medium">Pretend somebody is there</span>
            <select wire:model="simulate" class="{{ $field }}">
                <option value="">Use the real sensors</option>
                <option value="near">At the wall</option>
                <option value="room">In the room</option>
                <option value="empty">Room empty</option>
            </select>
            <span class="{{ $note }}">
                For trying the behaviour before the sensor is fitted. Remember to put it back.
            </span>
        </label>

        <button type="button" wire:click="saveProximity"
                class="touch-target mt-4 w-full rounded-xl bg-blue-600 font-semibold text-white">Save</button>

        {{-- What the sensors are actually saying, for when it is not behaving. --}}
        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950">
            <dt class="text-slate-500 dark:text-slate-400">Set up</dt>
            <dd>{{ $this->presenceState['configured'] ? 'yes' : 'no' }}</dd>
            <dt class="text-slate-500 dark:text-slate-400">Readable</dt>
            <dd>{{ $this->presenceState['readable'] ? 'yes' : 'no' }}{{ $this->presenceState['simulated'] ? ' (pretending)' : '' }}</dd>
            <dt class="text-slate-500 dark:text-slate-400">Near now</dt>
            <dd>{{ $this->presenceState['near'] ? 'yes' : 'no' }}</dd>
            <dt class="text-slate-500 dark:text-slate-400">In the room</dt>
            <dd>{{ $this->presenceState['room'] ? 'yes' : 'no' }}</dd>
            <dt class="text-slate-500 dark:text-slate-400">Last near</dt>
            <dd>{{ $this->presenceState['lastNear']?->diffForHumans() ?? '—' }}</dd>
            <dt class="text-slate-500 dark:text-slate-400">Listener</dt>
            <dd>{{ $this->presenceState['listening'] ? 'connected' : 'not running' }}</dd>
            @if ($this->presenceState['lux'] !== null)
                <dt class="text-slate-500 dark:text-slate-400">Light</dt>
                <dd>{{ $this->presenceState['lux'] }}</dd>
            @endif
        </dl>
    </section>

    {{-- ========================= WHO IS HOME ========================= --}}
    <section class="mb-6 rounded-2xl bg-white p-4 dark:bg-slate-900">
        <h2 class="font-semibold">Who is home</h2>
        <p class="{{ $note }}">
            The wall puts whoever is in first and collapses the rest to a row along the
            bottom. Anybody left unmapped is treated as in, so you can map one phone
            without everybody else disappearing.
        </p>

        @foreach ($this->members as $member)
            <label class="mt-3 block" wire:key="pres-{{ $member->id }}">
                <span class="flex items-center gap-2 text-sm font-medium">
                    <span class="size-3 rounded-full" style="background-color: {{ $member->colour }};"></span>
                    {{ $member->name }}
                </span>
                @if ($this->people->isNotEmpty())
                    <select wire:model="presence.{{ $member->id }}" class="{{ $field }}">
                        <option value="">Not tracked</option>
                        @foreach ($this->people as $entity)
                            <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }} — {{ $entity['entity_id'] }}</option>
                        @endforeach
                    </select>
                @else
                    <input wire:model="presence.{{ $member->id }}" type="text"
                           placeholder="person.{{ \Illuminate\Support\Str::slug($member->name, '_') }}" class="{{ $field }}">
                @endif
            </label>
        @endforeach

        <button type="button" wire:click="savePresence"
                class="touch-target mt-4 w-full rounded-xl bg-blue-600 font-semibold text-white">Save</button>

        <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950">
            <p class="font-medium">Right now</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($this->homeState['rows'] as $row)
                    <li class="flex items-baseline justify-between gap-3">
                        <span>{{ $row['name'] }}</span>
                        <span class="text-slate-500 dark:text-slate-400">
                            {{ $row['home'] ? 'in' : ($row['zone'] ?? 'out') }}
                            @if (! $row['mapped']) · not tracked @elseif ($row['raw']) · {{ $row['raw'] }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ======================== ACTIVITY FEED ======================== --}}
    <section class="mb-6 rounded-2xl bg-white p-4 dark:bg-slate-900">
        <h2 class="font-semibold">What the house is doing</h2>
        <p class="{{ $note }}">
            A line on the wall when something happens. Only the entities you add here —
            a Zigbee network invents a couple of hundred, and a feed of all of them is a
            feed nobody reads.
        </p>

        @if ($watching !== [])
            <ul class="mt-3 space-y-2">
                @foreach ($watching as $entityId => $config)
                    <li class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-950" wire:key="w-{{ $entityId }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ $config['label'] }}</span>
                            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                {{ ActivityEvent::CATEGORIES[$config['category']] }} · {{ $entityId }}
                            </span>
                        </span>
                        <button type="button" wire:click="unwatch(@js($entityId))"
                                class="touch-target shrink-0 rounded-xl px-3 text-sm font-semibold text-red-600">Remove</button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-3 rounded-xl border border-dashed border-slate-300 p-3 dark:border-slate-700">
            <label class="block">
                <span class="block text-sm font-medium">Entity</span>
                @if ($this->anything->isNotEmpty())
                    <select wire:model="newEntity" class="{{ $field }}">
                        <option value="">Choose one</option>
                        @foreach ($this->anything as $entity)
                            <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }} — {{ $entity['entity_id'] }}</option>
                        @endforeach
                    </select>
                @else
                    <input wire:model="newEntity" type="text" placeholder="binary_sensor.back_door" class="{{ $field }}">
                @endif
            </label>
            @error('newEntity') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <label class="mt-2 block">
                <span class="block text-sm font-medium">Call it</span>
                <input wire:model="newLabel" type="text" placeholder="Back door" class="{{ $field }}">
            </label>

            <label class="mt-2 block">
                <span class="block text-sm font-medium">Kind</span>
                <select wire:model="newCategory" class="{{ $field }}">
                    @foreach (ActivityEvent::CATEGORIES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <span class="{{ $note }}">Decides the words: a door opens, a lamp comes on, a thermostat goes to 20&deg;.</span>
            </label>

            <button type="button" wire:click="watch"
                    class="touch-target mt-3 w-full rounded-xl bg-slate-100 font-semibold dark:bg-slate-800">Add</button>
        </div>

        <label class="mt-4 flex touch-target items-center justify-between gap-4">
            <span class="min-w-0">
                <span class="block text-sm font-medium">Quiet hours</span>
                <span class="{{ $note }}">Lights and sockets stay out of the feed. People still appear.</span>
            </span>
            <input wire:model.live="quietEnabled" type="checkbox" class="size-6 shrink-0">
        </label>

        @if ($quietEnabled)
            <div class="mt-2 grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="block text-sm text-slate-500 dark:text-slate-400">From</span>
                    <input wire:model="quietStart" type="time" class="{{ $field }}">
                </label>
                <label class="block">
                    <span class="block text-sm text-slate-500 dark:text-slate-400">Until</span>
                    <input wire:model="quietEnd" type="time" class="{{ $field }}">
                </label>
            </div>
        @endif

        <button type="button" wire:click="saveQuiet"
                class="touch-target mt-3 w-full rounded-xl bg-blue-600 font-semibold text-white">Save quiet hours</button>

        @if ($this->recentActivity->isNotEmpty())
            <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950">
                <p class="font-medium">Last few</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($this->recentActivity as $event)
                        <li class="flex items-baseline justify-between gap-3">
                            <span class="truncate">{{ $event->label }} &rarr; {{ $event->to_state }}</span>
                            <span class="shrink-0 text-slate-500 dark:text-slate-400">{{ $event->at->diffForHumans(['parts' => 1]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- ========================== ANNOUNCE =========================== --}}
    <section class="mb-6 rounded-2xl bg-white p-4 dark:bg-slate-900">
        <h2 class="font-semibold">Announcements</h2>
        <p class="{{ $note }}">
            Four one-tap messages on the wall. With an Alexa chosen they come out of the
            speakers as well; without one they are a banner on the wall and nothing more.
        </p>

        @foreach ($presets as $at => $preset)
            <label class="mt-2 block" wire:key="preset-{{ $at }}">
                <span class="block text-sm text-slate-500 dark:text-slate-400">Message {{ $at + 1 }}</span>
                <input wire:model="presets.{{ $at }}" type="text" class="{{ $field }}">
            </label>
        @endforeach

        <label class="mt-4 block">
            <span class="block text-sm font-medium">Alexa notify service</span>
            @if ($this->notifyServices->isNotEmpty())
                <select wire:model="announceService" class="{{ $field }}">
                    <option value="">The wall only — say nothing out loud</option>
                    @foreach ($this->notifyServices as $service)
                        <option value="{{ $service['service'] }}">{{ $service['service'] }}</option>
                    @endforeach
                </select>
            @else
                <input wire:model="announceService" type="text" placeholder="notify.alexa_media_everywhere" class="{{ $field }}">
            @endif
            <span class="{{ $note }}">
                From the Alexa Media Player integration. One per Echo, plus an
                &ldquo;everywhere&rdquo; one that speaks on all of them. Leave it blank for the
                wall only.
                @if ($this->serviceError)
                    <span class="block text-red-600">Could not list them: {{ $this->serviceError }}</span>
                @endif
            </span>
        </label>

        <div class="mt-3">
            <span class="block text-sm font-medium">Speak on (optional)</span>
            <span class="{{ $note }}">
                Only needed for the generic <code>notify.alexa_media</code> service, which asks
                which devices. A per-device service and the &ldquo;everywhere&rdquo; one already
                know, and are refused if given a target.
            </span>
            @foreach ($announceTargets as $at => $target)
                <div class="mt-1 flex items-center gap-2" wire:key="target-{{ $at }}">
                    <input wire:model="announceTargets.{{ $at }}" type="text" class="{{ $field }} mt-0">
                    <button type="button" wire:click="removeTarget({{ $at }})"
                            class="touch-target shrink-0 rounded-xl px-3 text-sm font-semibold text-red-600">Remove</button>
                </div>
            @endforeach

            <div class="mt-2 flex items-center gap-2">
                <input wire:model="newTarget" type="text" placeholder="Kitchen Echo" class="{{ $field }} mt-0">
                <button type="button" wire:click="addTarget"
                        class="touch-target shrink-0 rounded-xl bg-slate-100 px-4 text-sm font-semibold dark:bg-slate-800">Add</button>
            </div>
        </div>

        <div class="mt-4 flex gap-2">
            <button type="button" wire:click="saveAnnounce"
                    class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
            <button type="button" wire:click="testAnnounce"
                    class="touch-target rounded-xl bg-slate-100 px-4 font-semibold dark:bg-slate-800">Test</button>
        </div>

        <p class="{{ $note }}">
            @if (! $this->speaks)
                No Alexa is set up — announcements show on the wall only.
            @elseif (! $this->connected)
                {{-- Configured is not the same as reachable, and saying
                     "will be spoken" about a Pi that is off is how an
                     announcement that does nothing looks like it worked. --}}
                <span class="text-amber-700 dark:text-amber-400">
                    {{ $announceService }} is set, but Home Assistant cannot be reached —
                    nothing will be said out loud until it is.
                </span>
            @else
                {{ $announceService }} is set up — announcements will be spoken.
            @endif
        </p>

        @if ($this->announcements !== [])
            <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950">
                <p class="font-medium">Last few</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach (array_slice($this->announcements, 0, 10) as $entry)
                        <li class="flex items-baseline justify-between gap-3">
                            <span class="truncate">{{ $entry['message'] }}</span>
                            <span class="shrink-0 text-slate-500 dark:text-slate-400">
                                {{ $entry['by'] }} · {{ $entry['spoken'] ? 'spoken' : 'wall only' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
