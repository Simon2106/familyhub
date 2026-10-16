<?php

namespace Tests\Feature\Display;

use App\Models\HomeTile;
use App\Models\Household;
use App\Models\Member;
use App\Models\SwitchGroup;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/**
 * One click handler per element.
 *
 * A `wire:click` beside an `x-on:click` works exactly once. The first press
 * re-renders the Alpine expression, the morph re-initialises that element's
 * Alpine directives, and Livewire's own listener is not among the ones it
 * puts back — so every tap after the first ran the Alpine half and reached
 * nothing. Alpine kept flipping the tile, which is what made it look like the
 * wall was working and the lights were broken.
 *
 * The group button had already been built the other way round for an
 * unrelated reason: there is no wire:click on it, because with both, a long
 * press fired the tap as well. Same shape, same conclusion.
 *
 * Rendered rather than read from the Blade, because the pattern is legitimate
 * in source — `<x-modal>` writes both, each behind its own `@if`, and only
 * ever renders one.
 */
class ClickHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ada', 'is_child' => true]);

        FakeHomeAssistant::fake();
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();

        parent::tearDown();
    }

    /** @return list<string> the offending elements, as markup */
    protected function bothHandlers(string $html): array
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // By name() rather than by @wire:click: to XPath a colon is a
        // namespace prefix, and there is no such namespace.
        $found = (new DOMXPath($document))->query(
            "//*[@*[starts-with(name(), 'wire:click')]]"
            ."[@*[starts-with(name(), 'x-on:click') or starts-with(name(), '@click')]]"
        );

        $offenders = [];

        foreach ($found as $element) {
            /** @var DOMElement $element */
            $offenders[] = substr($document->saveHTML($element), 0, 200);
        }

        return $offenders;
    }

    #[Test]
    public function no_element_on_the_wall_carries_both(): void
    {
        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        $this->assertSame([], $this->bothHandlers($html));
    }

    #[Test]
    public function no_element_on_the_switches_carries_both(): void
    {
        // The one this was written for: every kind of tile the panel draws,
        // so the cover and climate branches are rendered too.
        FakeHomeAssistant::entity('light.kitchen', 'off');
        FakeHomeAssistant::entity('switch.lamp', 'on');
        FakeHomeAssistant::entity('cover.blind', 'open');
        FakeHomeAssistant::entity('climate.hall', 'heat');
        FakeHomeAssistant::entity('light.landing', 'off');

        foreach ([
            'light.kitchen' => 'light',
            'switch.lamp' => 'switch',
            'cover.blind' => 'cover',
            'climate.hall' => 'climate',
        ] as $entityId => $domain) {
            HomeTile::create([
                'household_id' => $this->household->id,
                'entity_id' => $entityId,
                'name' => $entityId,
                'domain' => $domain,
            ]);
        }

        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);
        $group->entities()->create(['entity_id' => 'light.landing', 'name' => 'Landing']);

        $html = $this->withoutVite()->get(route('switches'))->assertOk()->getContent();

        $this->assertSame([], $this->bothHandlers($html));
    }
}
