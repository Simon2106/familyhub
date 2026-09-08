<?php

namespace Tests\Feature\Capture;

use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Models\User;
use App\Services\Capture\SenderHint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who a forwarded email is about, from where it came from.
 *
 * School letters are famously bad at saying whose child they concern, and the
 * domain says it outright.
 */
class SenderHintTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
    }

    protected function hint(): SenderHint
    {
        return app(SenderHint::class);
    }

    #[Test]
    public function a_domain_is_read_off_an_address(): void
    {
        $this->assertSame('holytrinity.bucks.sch.uk',
            $this->hint()->domainOf('Office <office@holytrinity.bucks.sch.uk>'));
        $this->assertSame('example.test', $this->hint()->domainOf('someone@example.test'));
        $this->assertNull($this->hint()->domainOf('no address here'));
        $this->assertNull($this->hint()->domainOf(null));
    }

    #[Test]
    public function a_domain_on_a_member_says_the_letter_is_about_them(): void
    {
        $this->joey->aliases()->create(['alias' => 'holytrinity.bucks.sch.uk', 'kind' => 'domain']);

        $sentence = $this->hint()->sentence('office@holytrinity.bucks.sch.uk', $this->household);

        $this->assertStringContainsString('holytrinity.bucks.sch.uk', $sentence);
        $this->assertStringContainsString('belongs to Joey', $sentence);
        $this->assertStringContainsString('concerns them', $sentence);
    }

    #[Test]
    public function a_subdomain_still_counts_as_the_school(): void
    {
        $this->joey->aliases()->create(['alias' => 'holytrinity.bucks.sch.uk', 'kind' => 'domain']);

        $hint = $this->hint()->forSender('noreply@mail.holytrinity.bucks.sch.uk', $this->household);

        $this->assertSame(['Joey'], $hint['members']);
    }

    #[Test]
    public function two_children_at_the_same_school_are_both_named(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Sienna', 'is_child' => true]);

        foreach ([$this->joey, $sienna] as $child) {
            $child->aliases()->create(['alias' => 'holytrinity.bucks.sch.uk', 'kind' => 'domain']);
        }

        $sentence = $this->hint()->sentence('office@holytrinity.bucks.sch.uk', $this->household);

        $this->assertStringContainsString('Joey and Sienna', $sentence);
        $this->assertStringContainsString('one or more of them', $sentence);
    }

    #[Test]
    public function a_place_can_own_a_domain_too(): void
    {
        $place = Place::create(['household_id' => $this->household->id, 'name' => 'Holy Trinity School', 'type' => 'school']);
        $place->aliases()->create(['alias' => 'holytrinity.bucks.sch.uk', 'kind' => 'domain']);

        $sentence = $this->hint()->sentence('office@holytrinity.bucks.sch.uk', $this->household);

        $this->assertStringContainsString('which is Holy Trinity School', $sentence);
    }

    #[Test]
    public function an_unknown_domain_is_still_worth_mentioning(): void
    {
        $sentence = $this->hint()->sentence('someone@unknown.test', $this->household);

        $this->assertSame('Sent from unknown.test.', $sentence);
    }

    #[Test]
    public function a_domain_is_never_matched_against_an_event_title(): void
    {
        // Nobody writes "holytrinity.bucks.sch.uk" on a wall calendar, and
        // matching it there would attribute anything mentioning the string.
        $this->joey->aliases()->create(['alias' => 'holytrinity.bucks.sch.uk', 'kind' => 'domain']);
        $this->joey->aliases()->create(['alias' => 'JW', 'kind' => 'name']);

        $terms = $this->joey->fresh()->load('aliases')->matchTerms();

        $this->assertContains('JW', $terms);
        $this->assertNotContains('holytrinity.bucks.sch.uk', $terms);
    }

    #[Test]
    public function domains_are_edited_in_admin_and_kept_apart_from_name_aliases(): void
    {
        Livewire::test('admin.settings')
            ->call('edit', $this->joey->id)
            ->set('aliases', 'JW, Joseph')
            ->set('domains', 'Holytrinity.Bucks.Sch.UK, office@stmarys.test')
            ->call('saveMember')
            ->assertHasNoErrors();

        $aliases = $this->joey->fresh()->load('aliases')->aliases;

        // Not "Joey": a one-word name needs no alias, matchTerms() already
        // includes the name itself.
        $this->assertEqualsCanonicalizing(
            ['JW', 'Joseph'],
            $aliases->where('kind', 'name')->pluck('alias')->all(),
        );

        // Lowercased, and an address pasted whole is reduced to its domain.
        $this->assertEqualsCanonicalizing(
            ['holytrinity.bucks.sch.uk', 'stmarys.test'],
            $aliases->where('kind', 'domain')->pluck('alias')->all(),
        );
    }

    #[Test]
    public function removing_a_domain_leaves_the_name_aliases_alone(): void
    {
        $this->joey->aliases()->create(['alias' => 'JW', 'kind' => 'name']);
        $this->joey->aliases()->create(['alias' => 'old.test', 'kind' => 'domain']);

        Livewire::test('admin.settings')
            ->call('edit', $this->joey->id)
            ->set('domains', '')
            ->call('saveMember');

        $aliases = $this->joey->fresh()->load('aliases')->aliases;

        $this->assertContains('JW', $aliases->where('kind', 'name')->pluck('alias')->all());
        $this->assertCount(0, $aliases->where('kind', 'domain'));
    }
}
