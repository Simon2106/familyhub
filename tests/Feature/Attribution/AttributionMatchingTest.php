<?php

namespace Tests\Feature\Attribution;

use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Services\Attribution\AttributionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttributionMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
    }

    protected function member(string $name, array $aliases = []): Member
    {
        $member = Member::factory()->create(['household_id' => $this->household->id, 'name' => $name]);

        foreach ($aliases as $alias) {
            $member->aliases()->create(['alias' => $alias]);
        }

        return $member;
    }

    /** @param array<int, bool> $members member id => include automatically */
    protected function place(string $name, string $type, array $aliases, array $members): Place
    {
        $place = Place::factory()->create([
            'household_id' => $this->household->id,
            'name' => $name,
            'type' => $type,
        ]);

        foreach ($aliases as $alias) {
            $place->aliases()->create(['alias' => $alias]);
        }

        foreach ($members as $memberId => $auto) {
            $place->members()->attach($memberId, ['include_automatically' => $auto]);
        }

        return $place;
    }

    protected function matcher(): AttributionMatcher
    {
        return AttributionMatcher::forHousehold($this->household->fresh());
    }

    #[Test]
    public function it_matches_a_members_own_name(): void
    {
        $simon = $this->member('Simon');

        $this->assertSame([$simon->id => 'alias'], $this->matcher()->match('Simon dentist'));
    }

    #[Test]
    public function it_matches_a_short_alias(): void
    {
        $simon = $this->member('Simon', ['SW']);

        $this->assertSame([$simon->id => 'alias'], $this->matcher()->match('SW dentist'));
    }

    #[Test]
    public function it_matches_several_members_in_one_title(): void
    {
        $simon = $this->member('Simon', ['SW']);
        $jenna = $this->member('Jenna', ['JW']);

        $matched = $this->matcher()->match('SW + JW dentist');

        $this->assertEqualsCanonicalizing([$simon->id, $jenna->id], array_keys($matched));
    }

    #[Test]
    public function it_matches_a_multi_word_phrase(): void
    {
        $simon = $this->member('Simon');
        $this->place('Ice', 'work', ['Ice and a Slice', 'IAAS'], [$simon->id => true]);

        $this->assertSame([$simon->id => 'place'], $this->matcher()->match('Ice and a Slice quarterly review'));
        $this->assertSame([$simon->id => 'place'], $this->matcher()->match('IAAS quarterly review'));
    }

    #[Test]
    public function a_multi_word_phrase_does_not_match_when_split_up(): void
    {
        $simon = $this->member('Simon');
        $this->place('Nowhere', 'work', ['Ice and a Slice'], [$simon->id => true]);

        // "Slice" and "Ice" both appear, but not as the phrase.
        $this->assertSame([], $this->matcher()->match('Slice of cake and cream'));
    }

    #[Test]
    public function extra_whitespace_in_a_title_does_not_break_a_phrase(): void
    {
        $simon = $this->member('Simon');
        $this->place('Ice', 'work', ['Ice and a Slice'], [$simon->id => true]);

        $this->assertSame([$simon->id => 'place'], $this->matcher()->match("Ice  and a\tSlice review"));
    }

    #[Test]
    public function a_place_pulls_in_only_the_members_opted_into_it(): void
    {
        $simon = $this->member('Simon', ['SW']);
        $jenna = $this->member('Jenna', ['JW']);

        // Ice belongs to both, but only Simon is added automatically.
        $this->place('Ice', 'work', ['IAAS'], [$simon->id => true, $jenna->id => false]);

        $this->assertSame([$simon->id => 'place'], $this->matcher()->match('Ice all-hands'));
    }

    #[Test]
    public function a_name_alias_overrides_being_opted_out_of_a_place(): void
    {
        $simon = $this->member('Simon', ['SW']);
        $jenna = $this->member('Jenna', ['JW']);

        $this->place('Ice', 'work', ['IAAS'], [$simon->id => true, $jenna->id => false]);

        $matched = $this->matcher()->match('Ice Christmas party, JW coming too');

        $this->assertEqualsCanonicalizing([$simon->id, $jenna->id], array_keys($matched));
        $this->assertSame('place', $matched[$simon->id]);
        $this->assertSame('alias', $matched[$jenna->id]);
    }

    #[Test]
    public function a_name_beats_a_place_as_the_recorded_reason(): void
    {
        $simon = $this->member('Simon', ['SW']);
        $this->place('Ice', 'work', [], [$simon->id => true]);

        $this->assertSame([$simon->id => 'alias'], $this->matcher()->match('SW at Ice'));
    }

    #[Test]
    public function a_short_alias_does_not_match_inside_a_longer_word(): void
    {
        $this->member('Joanna', ['Jo']);

        $this->assertSame([], $this->matcher()->match('Meeting with Mr Jones'));
        $this->assertSame([], $this->matcher()->match('Jogging'));
        $this->assertSame([], $this->matcher()->match('Banjo lesson'));
    }

    #[Test]
    public function a_short_alias_still_matches_next_to_punctuation(): void
    {
        $jo = $this->member('Joanna', ['Jo']);

        foreach (['Jo, dentist', 'Dentist (Jo)', 'Jo/Sam swimming', 'Jo - haircut', 'dentist for Jo'] as $title) {
            $this->assertSame([$jo->id => 'alias'], $this->matcher()->match($title), "failed on: {$title}");
        }
    }

    #[Test]
    public function matching_ignores_case(): void
    {
        $simon = $this->member('Simon', ['SW']);

        $this->assertSame([$simon->id => 'alias'], $this->matcher()->match('sw dentist'));
        $this->assertSame([$simon->id => 'alias'], $this->matcher()->match('SIMON dentist'));
    }

    #[Test]
    public function it_matches_on_the_location_as_well_as_the_title(): void
    {
        $sienna = $this->member('Sienna');
        $this->place('Sandy Gate', 'school', ['SG'], [$sienna->id => true]);

        $this->assertSame([$sienna->id => 'place'], $this->matcher()->match('Parents evening', 'Sandy Gate'));
    }

    #[Test]
    public function two_schools_attribute_to_their_own_children(): void
    {
        $sienna = $this->member('Sienna');
        $joey = $this->member('Joey');

        $this->place('Sandy Gate', 'school', ['SG'], [$sienna->id => true]);
        $this->place('Holy Trinity', 'school', ['HT'], [$joey->id => true]);

        $this->assertSame([$sienna->id => 'place'], $this->matcher()->match('SG inset day'));
        $this->assertSame([$joey->id => 'place'], $this->matcher()->match('HT sports day'));
        $this->assertEqualsCanonicalizing(
            [$sienna->id, $joey->id],
            array_keys($this->matcher()->match('SG and HT both closed')),
        );
    }

    #[Test]
    public function an_unrecognised_title_matches_nobody(): void
    {
        $this->member('Simon', ['SW']);

        $this->assertSame([], $this->matcher()->match('Bin day'));
    }

    #[Test]
    public function an_empty_title_matches_nobody(): void
    {
        $this->member('Simon', ['SW']);

        $this->assertSame([], $this->matcher()->match('', null));
    }
}
