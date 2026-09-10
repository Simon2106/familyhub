<x-mail::message>
# Hello {{ $user->name }}

@if ($notices->count() === 1)
Here is the one thing you missed.
@else
Here are {{ $notices->count() }} things from while you were away.
@endif

@foreach ($notices as $notice)
**{{ $notice->title }}**
@if ($notice->body)
{{ $notice->body }}
@endif

@endforeach
@if ($week)

---

## The week — {{ $week->heading() }}

@foreach ($week->children as $child)
**{{ $child->member->name }}** — {{ $child->sentence() }}, {{ $child->balance }} stars in the bank@if ($child->choresWaiting > 0), {{ $child->choresWaiting }} waiting to be signed off@endif

@endforeach
@if ($week->meals->isNotEmpty())

**What we ate**

@foreach ($week->meals as $meal)
- {{ $meal->on->format('D') }} — {{ $meal->title }}@if ($meal->verdict()) ({{ $meal->verdict() }})@endif

@endforeach
@endif

**Coming up, {{ $week->ahead->weekStart->format('j M') }}**

@if ($week->ahead->isQuiet())
Nothing in the calendar. A quiet one.
@else
@foreach ($week->ahead->highlights as $item)
- {{ $item['when']->format('D') }}@if (! $item['all_day']) {{ $item['when']->format('H:i') }}@endif — {{ $item['title'] }}

@endforeach
@endif

{{ $week->ahead->mealSentence() }}.
@endif

<x-mail::button :url="route('app')">
Open FamilyHub
</x-mail::button>

You can change what you are told about, or turn this off, under Notifications.
</x-mail::message>
