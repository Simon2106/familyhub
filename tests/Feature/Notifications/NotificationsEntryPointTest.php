<?php

namespace Tests\Feature\Notifications;

use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Notifications\NeedsAttention;
use App\Services\Notifications\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Getting to the notification settings at all.
 *
 * The page worked long before anything linked to it, which is its own kind of
 * bug: a setting nobody can find is a setting nobody has.
 */
class NotificationsEntryPointTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->user = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_phone_header_offers_a_way_in(): void
    {
        Livewire::test('phone.home')
            ->assertSee(route('notifications'), false)
            ->assertSee('Notifications', false);
    }

    #[Test]
    public function the_bell_is_plain_when_nothing_is_waiting(): void
    {
        Livewire::test('phone.home')
            ->assertSet('waitingOnAGrownUp', 0)
            ->assertDontSee('waiting for a grown-up');
    }

    #[Test]
    public function something_in_the_review_inbox_puts_a_dot_on_the_bell(): void
    {
        CaptureItem::factory()->create([
            'capture_id' => Capture::factory()->create([
                'household_id' => $this->household->id,
            ])->id,
            'status' => 'pending',
        ]);

        Livewire::test('phone.home')
            ->assertSet('waitingOnAGrownUp', 1)
            ->assertSee('1 waiting for a grown-up', false);
    }

    #[Test]
    public function a_chore_waiting_to_be_signed_off_puts_a_dot_on_the_bell(): void
    {
        $child = Member::factory()->create([
            'household_id' => $this->household->id, 'is_child' => true,
        ]);

        $chore = Chore::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $child->id,
            'needs_approval' => true,
            'points' => 5,
        ]);

        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-10'), $child);

        Livewire::test('phone.home')->assertSet('waitingOnAGrownUp', 1);
    }

    /**
     * The bell and the notice it leads to must agree about what "waiting"
     * means, which is why there is one class that decides.
     */
    #[Test]
    public function the_bell_counts_what_the_notifications_count(): void
    {
        $child = Member::factory()->create([
            'household_id' => $this->household->id, 'is_child' => true,
        ]);

        $reward = Reward::factory()->create(['household_id' => $this->household->id]);

        Redemption::create([
            'household_id' => $this->household->id,
            'member_id' => $child->id,
            'reward_id' => $reward->id,
            'name' => $reward->name,
            'cost' => 5,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $attention = app(NeedsAttention::class);

        $this->assertSame(1, $attention->approvals($this->household));
        $this->assertSame(1, $attention->total($this->household));
        $this->assertTrue($attention->any($this->household));

        Livewire::test('phone.home')->assertSet('waitingOnAGrownUp', 1);
    }

    /* --------------------------- settings --------------------------- */

    #[Test]
    public function admin_settings_lists_notifications(): void
    {
        Livewire::test('admin.settings')
            ->assertSee('Notifications')
            ->assertSee(route('notifications'), false);
    }

    #[Test]
    public function the_settings_row_says_what_this_person_is_told_about(): void
    {
        config()->set('familyhub.push.public_key', 'test-public');
        config()->set('familyhub.push.private_key', 'test-private');

        Livewire::test('admin.settings')
            ->assertSee('You are not being told about anything yet.');

        app(NotificationSettings::class)->put($this->user, [
            'event_reminder' => true,
            'weekly_summary' => true,
        ], 60);

        Livewire::test('admin.settings')->assertSee('2 things you are told about.');
    }

    #[Test]
    public function the_settings_row_says_so_when_push_is_not_set_up_at_all(): void
    {
        config()->set('familyhub.push.public_key', null);
        config()->set('familyhub.push.private_key', null);

        Livewire::test('admin.settings')->assertSee('no push keys on the server');
    }

    /**
     * Written down because it was the actual request: this must work for
     * whichever parent is signed in, not just the one who set it up.
     */
    #[Test]
    public function it_is_the_same_for_the_other_parent(): void
    {
        $jenna = User::factory()->create(['household_id' => $this->household->id]);

        $this->actingAs($jenna);

        Livewire::test('phone.home')->assertSee(route('notifications'), false);
        Livewire::test('admin.settings')->assertSee('Notifications');

        $this->get(route('notifications'))->assertOk();
    }
}
