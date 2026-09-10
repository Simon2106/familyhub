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

<x-mail::button :url="route('app')">
Open FamilyHub
</x-mail::button>

You can change what you are told about, or turn this off, under Notifications.
</x-mail::message>
