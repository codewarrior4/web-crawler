<?php

namespace Tests\Feature;

use App\Models\CrawlTarget;
use Database\Seeders\CrawlTargetSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageCrawlTargetsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_business_target_with_trial_defaults_without_fetching_pages(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('crawl:targets', [
            'action' => 'create',
            '--name' => 'Example business',
            '--url' => 'https://example.com/contact/',
            '--type' => 'business',
        ])->expectsOutputToContain('Created crawl target')->assertSuccessful();

        $this->assertDatabaseHas('crawl_targets', [
            'name' => 'Example business',
            'url' => 'https://example.com/contact/',
            'type' => 'business',
            'is_enabled' => true,
            'max_pages' => 25,
            'max_depth' => 2,
        ]);
        Http::assertNothingSent();
    }

    public function test_creates_a_disabled_job_target_with_custom_limits_and_http_url(): void
    {
        $this->artisan('crawl:targets', [
            'action' => 'create',
            '--name' => 'Example jobs',
            '--url' => 'http://example.com/jobs/',
            '--type' => 'job',
            '--enabled' => '0',
            '--max-pages' => '1',
            '--max-depth' => '0',
        ])->assertSuccessful();

        $target = CrawlTarget::sole();
        $this->assertSame('job', $target->type);
        $this->assertFalse($target->is_enabled);
        $this->assertSame(1, $target->max_pages);
        $this->assertSame(0, $target->max_depth);
    }

    public function test_lists_enabled_and_disabled_targets(): void
    {
        $business = CrawlTarget::factory()->create(['name' => 'Example business']);
        $job = CrawlTarget::factory()->jobs()->disabled()->create(['name' => 'Example jobs']);

        $this->artisan('crawl:targets')->expectsTable(
            ['ID', 'Name', 'URL', 'Type', 'Enabled', 'Pages', 'Depth'],
            [
                [$business->id, 'Example business', 'https://example.com/', 'business', 'yes', 25, 2],
                [$job->id, 'Example jobs', 'https://example.com/', 'job', 'no', 25, 2],
            ],
        )->assertSuccessful();
    }

    public function test_lists_an_empty_table_when_no_targets_exist(): void
    {
        $this->artisan('crawl:targets')->expectsTable(
            ['ID', 'Name', 'URL', 'Type', 'Enabled', 'Pages', 'Depth'], [],
        )->assertSuccessful();
    }

    public function test_updates_a_target_and_preserves_omitted_fields(): void
    {
        $target = CrawlTarget::factory()->disabled()->create(['name' => 'Original name']);

        $this->artisan('crawl:targets', [
            'action' => 'update',
            'id' => $target->id,
            '--name' => 'Updated name',
            '--enabled' => '1',
            '--max-pages' => '1000',
            '--max-depth' => '10',
        ])->expectsOutput("Updated crawl target {$target->id}.")->assertSuccessful();

        $this->assertDatabaseHas('crawl_targets', [
            'id' => $target->id,
            'name' => 'Updated name',
            'url' => 'https://example.com/',
            'type' => 'business',
            'is_enabled' => true,
            'max_pages' => 1000,
            'max_depth' => 10,
        ]);
    }

    public function test_can_change_a_targets_url_and_type(): void
    {
        $target = CrawlTarget::factory()->create();

        $this->artisan('crawl:targets', [
            'action' => 'update', 'id' => $target->id,
            '--url' => 'https://example.org/jobs/', '--type' => 'job',
        ])->assertSuccessful();

        $this->assertDatabaseHas('crawl_targets', [
            'id' => $target->id, 'url' => 'https://example.org/jobs/', 'type' => 'job',
        ]);
    }

    public function test_disables_a_target_without_deleting_it(): void
    {
        $target = CrawlTarget::factory()->create();

        $this->artisan('crawl:targets', ['action' => 'disable', 'id' => $target->id])
            ->expectsOutput("Disabled crawl target {$target->id}.")->assertSuccessful();

        $this->assertDatabaseHas('crawl_targets', ['id' => $target->id, 'is_enabled' => false]);
    }

    #[DataProvider('invalidTargetOptions')]
    public function test_rejects_invalid_create_options_without_saving(string $option, string $value, string $message): void
    {
        $options = [
            'action' => 'create', '--name' => 'Example',
            '--url' => 'https://example.com/', '--type' => 'business',
        ];
        $options[$option] = $value;

        $this->artisan('crawl:targets', $options)
            ->expectsOutputToContain($message)->assertFailed();

        $this->assertDatabaseCount('crawl_targets', 0);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidTargetOptions(): array
    {
        return [
            'missing name' => ['--name', '', 'name'],
            'long name' => ['--name', str_repeat('a', 256), 'name'],
            'terminal control in name' => ['--name', "Bad\x1Bname", 'name'],
            'missing url' => ['--url', '', 'url'],
            'malformed url' => ['--url', 'not-a-url', 'url'],
            'unsupported scheme' => ['--url', 'ftp://example.com/', 'url'],
            'javascript scheme' => ['--url', 'javascript:alert(1)', 'url'],
            'url credentials' => ['--url', 'https://user:secret@example.com/', 'credentials'],
            'url username only' => ['--url', 'https://user@example.com/', 'credentials'],
            'long url' => ['--url', 'https://example.com/'.str_repeat('a', 2048), 'url'],
            'invalid type' => ['--type', 'news', 'type'],
            'missing type' => ['--type', '', 'type'],
            'invalid enabled' => ['--enabled', 'yes', 'enabled'],
            'empty enabled' => ['--enabled', '', 'enabled'],
            'zero pages' => ['--max-pages', '0', 'pages'],
            'too many pages' => ['--max-pages', '1001', 'pages'],
            'fractional pages' => ['--max-pages', '2.5', 'pages'],
            'empty pages' => ['--max-pages', '', 'pages'],
            'negative depth' => ['--max-depth', '-1', 'depth'],
            'too much depth' => ['--max-depth', '11', 'depth'],
            'noninteger depth' => ['--max-depth', 'deep', 'depth'],
        ];
    }

    public function test_requires_name_url_and_type_to_create_a_target(): void
    {
        $this->artisan('crawl:targets', ['action' => 'create'])
            ->expectsOutputToContain('name')->expectsOutputToContain('url')
            ->expectsOutputToContain('type')->assertFailed();

        $this->assertDatabaseCount('crawl_targets', 0);
    }

    public function test_invalid_update_does_not_partially_change_a_target(): void
    {
        $target = CrawlTarget::factory()->create(['name' => 'Original name']);

        $this->artisan('crawl:targets', [
            'action' => 'update', 'id' => $target->id,
            '--name' => 'Changed name', '--url' => 'file:///etc/passwd',
        ])->expectsOutputToContain('url')->assertFailed();

        $this->assertDatabaseHas('crawl_targets', [
            'id' => $target->id, 'name' => 'Original name', 'url' => 'https://example.com/',
        ]);
    }

    #[DataProvider('invalidCommandArguments')]
    public function test_rejects_invalid_command_arguments(array $arguments, string $message): void
    {
        $this->artisan('crawl:targets', $arguments)->expectsOutput($message)->assertFailed();

        $this->assertDatabaseCount('crawl_targets', 0);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidCommandArguments(): array
    {
        return [
            'unknown action' => [['action' => 'delete'], 'Action must be list, create, update, or disable.'],
            'list with id' => [['action' => 'list', 'id' => '1'], 'Only update and disable accept a target ID.'],
            'create with id' => [['action' => 'create', 'id' => '1'], 'Only update and disable accept a target ID.'],
            'list with options' => [['--name' => 'Ignored'], 'Only create and update accept target options.'],
            'disable with options' => [['action' => 'disable', 'id' => '1', '--enabled' => '1'], 'Only create and update accept target options.'],
            'update without id' => [['action' => 'update'], 'A positive target ID is required.'],
            'disable without id' => [['action' => 'disable'], 'A positive target ID is required.'],
            'invalid id' => [['action' => 'update', 'id' => 'abc'], 'A positive target ID is required.'],
            'zero id' => [['action' => 'disable', 'id' => '0'], 'A positive target ID is required.'],
            'missing update target' => [['action' => 'update', 'id' => '999'], 'Crawl target not found.'],
            'missing disable target' => [['action' => 'disable', 'id' => '999'], 'Crawl target not found.'],
        ];
    }

    public function test_requires_at_least_one_option_for_updates(): void
    {
        $target = CrawlTarget::factory()->create();

        $this->artisan('crawl:targets', ['action' => 'update', 'id' => $target->id])
            ->expectsOutput('Provide at least one target option to update.')->assertFailed();
    }

    public function test_trial_seeder_is_repeatable_and_preserves_existing_settings(): void
    {
        $this->seed(CrawlTargetSeeder::class);
        CrawlTarget::where('type', 'business')->sole()->update(['is_enabled' => false, 'max_pages' => 5]);
        $this->seed(CrawlTargetSeeder::class);

        $this->assertDatabaseCount('crawl_targets', 2);
        $this->assertDatabaseHas('crawl_targets', [
            'url' => 'https://automattic.com/contact/', 'type' => 'business',
            'is_enabled' => false, 'max_pages' => 5,
        ]);
        $this->assertDatabaseHas('crawl_targets', [
            'url' => 'https://www.python.org/jobs/', 'type' => 'job', 'max_depth' => 2,
        ]);
    }
}
