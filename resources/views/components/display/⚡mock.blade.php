<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Home face, built static, against the mockup.
 *
 * Sample content exactly as the design has it, so the thing being compared
 * side by side with design/home-mockup.png is the layout and nothing else —
 * no live data to shift under the screenshot, no empty household to make a
 * column look wrong. Behind the display token with the rest of the wall,
 * and it links nowhere.
 *
 * Once this matches, the same markup takes live data and becomes the Home
 * face. This page stays, because a static reference is the only honest way
 * to tell a layout regression from a data one.
 */
new #[Layout('layouts::display')] class extends Component
{
    /** The mockup's own palette, member by member. */
    public const PEOPLE = [
        ['slug' => 'simon', 'name' => 'Simon', 'colour' => '#3B82F6'],
        ['slug' => 'jenna', 'name' => 'Jenna', 'colour' => '#F2458F'],
        ['slug' => 'joey', 'name' => 'Joey', 'colour' => '#F5B800', 'chores' => ['🎒 Take KB bag upstairs']],
        ['slug' => 'sienna', 'name' => 'Sienna', 'colour' => '#8B5CF6'],
    ];

    /** @var list<array<string, mixed>> */
    public const HOUSEHOLD = [
        ['when' => 'All day', 'title' => 'Max Birthday 🎁', 'colour' => '#F2458F'],
        ['when' => '09:00', 'title' => 'Louise Roughly 🩵', 'subtitle' => '1 Temple Lane', 'colour' => '#3B82F6'],
        ['when' => '11:30', 'title' => 'Mama & Papa 🩵', 'colour' => '#F5B800'],
    ];

    /** @var list<array<string, mixed>> */
    public const TODAY = [
        ['when' => 'All day', 'title' => 'Max Birthday 🎁', 'subtitle' => 'Household', 'colour' => '#F2458F'],
        ['when' => '09:00', 'title' => 'Louise Roughly 🩵', 'subtitle' => 'Household · 1 Temple Lane', 'colour' => '#3B82F6'],
        ['when' => '11:30', 'title' => 'Mama & Papa 🩵', 'subtitle' => 'Household', 'colour' => '#F5B800'],
    ];
}; ?>

@php
    $days = [
        ['Mon', 12, []],
        ['Tue', 13, ['#F2458F', '#8B5CF6']],
        ['Wed', 14, ['#3B82F6', '#F5B800']],
        ['Thu', 15, []],
        ['Fri', 16, [], true],
        ['Sat', 17, ['#34C759', '#3B82F6']],
        ['Sun', 18, []],
    ];
@endphp

<div class="wall wall-ground app-shell relative flex flex-col overflow-hidden"
     x-data="{ tab: 'home', view: 'week' }" data-wall-mock>
    <x-wall.face
        household="The Wills"
        clock="14:53"
        date="Friday, 16 October"
        temperature="15°"
        weather="Mostly sunny · 15°/9° · 100% rain"
        :people="$this::PEOPLE"
        :days="$days"
        :household-events="$this::HOUSEHOLD"
        :household-count="3"
        :today-lines="$this::TODAY"
    />

    <x-wall.tab-bar
        :tabs="[
            ['home', 'Home', 'house'],
            ['review', 'Review', 'inbox'],
            ['meals', 'Meals', 'cutlery'],
            ['lists', 'Lists', 'list'],
            ['switches', 'Switches', 'toggle'],
            ['photos', 'Photos', 'photo'],
            ['timers', 'Timers', 'timer'],
            ['activity', 'Activity', 'bars'],
        ]"
    />
</div>
