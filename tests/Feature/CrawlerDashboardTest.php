<?php

namespace Tests\Feature;

use App\Jobs\ProcessCrawl;
use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_and_nonoperators_are_forbidden(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/results/export')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->post('/targets', [])->assertForbidden();
    }

    public function test_operator_can_login_and_logout_but_public_user_cannot_login(): void
    {
        $operator = User::factory()->operator()->create(['password' => Hash::make('StrongPassword123!')]);
        $this->post('/login', ['email' => $operator->email, 'password' => 'StrongPassword123!'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($operator);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $normal = User::factory()->create(['password' => Hash::make('StrongPassword123!')]);
        $this->post('/login', ['email' => $normal->email, 'password' => 'StrongPassword123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_operator_can_create_update_and_disable_targets(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $payload = ['name' => 'Example', 'url' => 'https://example.com/', 'type' => 'business', 'max_pages' => 3, 'max_depth' => 1, 'is_enabled' => 1, 'schedule_hours' => 24];
        $this->post('/targets', $payload)->assertRedirect('/dashboard');
        $target = CrawlTarget::sole();
        $this->assertSame(24, $target->schedule_hours);
        $this->get('/targets/'.$target->id.'/edit')->assertOk()->assertSee('Example');
        unset($payload['is_enabled']);
        $payload['name'] = 'Changed';
        $this->put('/targets/'.$target->id, $payload)->assertRedirect('/dashboard');
        $this->assertFalse($target->fresh()->is_enabled);
        $this->assertSame('Changed', $target->fresh()->name);
    }

    public function test_target_validation_rejects_bad_urls_and_limits(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->post('/targets', ['name' => 'Bad', 'url' => 'https://user:secret@example.com/', 'type' => 'other', 'max_pages' => 0, 'max_depth' => -1])
            ->assertSessionHasErrors(['url', 'type', 'max_pages', 'max_depth']);
        $this->assertDatabaseCount('crawl_targets', 0);
    }

    public function test_operator_can_queue_view_and_cancel_a_crawl(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->operator()->create());
        $target = CrawlTarget::factory()->create();
        $this->post('/targets/'.$target->id.'/runs')->assertRedirect();
        $run = CrawlRun::sole();
        Queue::assertPushed(ProcessCrawl::class, 1);
        $this->get('/runs')->assertOk();
        $this->get('/runs/'.$run->id)->assertOk()->assertSee('Page activity');
        $this->delete('/runs/'.$run->id)->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_disabled_target_is_not_queued(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->operator()->create());
        $target = CrawlTarget::factory()->disabled()->create();
        $this->post('/targets/'.$target->id.'/runs')->assertSessionHasErrors('target');
        Queue::assertNothingPushed();
    }

    public function test_results_support_filters_details_and_formula_safe_csv(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $one = ExtractedRecord::factory()->create(['title' => '=Dangerous', 'search_text' => 'Acme Lagos', 'data' => ['name' => '=Dangerous', 'emails' => ['hello@example.com']]]);
        $other = ExtractedRecord::factory()->create(['kind' => 'job', 'title' => 'Engineer', 'search_text' => 'Engineer Remote', 'expires_at' => now()->subDay()]);
        $this->get('/results?q=Lagos&kind=business')->assertOk()->assertSee('=Dangerous')->assertDontSee('Engineer');
        $this->get('/results?expiry=expired')->assertOk()->assertSee('Engineer')->assertDontSee('=Dangerous');
        $this->get('/results/'.$one->id)->assertOk()->assertSee('hello@example.com');
        $response = $this->get('/results/export?kind=business')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Dangerous", $csv);
        $this->assertStringNotContainsString('Engineer', $csv);
        $this->assertStringContainsString('hello@example.com', $csv);
    }

    public function test_dashboard_escapes_untrusted_names_and_renders_empty_states(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->get('/dashboard')->assertOk()->assertSee('Every good search');
        CrawlTarget::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->get('/dashboard')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_search_treats_percent_and_underscore_as_literal_text(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        ExtractedRecord::factory()->create(['title' => 'Literal match', 'search_text' => '100%_ready']);
        ExtractedRecord::factory()->create(['title' => 'Not a match', 'search_text' => '100abready']);
        $this->get('/results?q='.urlencode('100%_'))->assertOk()->assertSee('Literal match')->assertDontSee('Not a match');
    }

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_operator_creation_command_hashes_password_and_denies_duplicate_email(): void
    {
        $this->artisan('crawler:user', ['email' => 'new@example.com'])
            ->expectsQuestion('New password (at least 12 characters)', 'Long-Password-123!')->assertSuccessful();
        $user = User::where('email', 'new@example.com')->sole();
        $this->assertTrue($user->is_operator);
        $this->assertTrue(Hash::check('Long-Password-123!', $user->password));
        $this->artisan('crawler:user', ['email' => 'new@example.com'])
            ->expectsQuestion('New password (at least 12 characters)', 'Another-Password-123!')->assertFailed();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'invalid@example.com', 'password' => 'bad']);
        }
        $this->post('/login', ['email' => 'invalid@example.com', 'password' => 'bad'])->assertStatus(429);
    }
}
