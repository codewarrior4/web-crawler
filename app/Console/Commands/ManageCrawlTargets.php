<?php

namespace App\Console\Commands;

use App\Models\CrawlTarget;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ManageCrawlTargets extends Command
{
    protected $signature = 'crawl:targets
        {action=list : list, create, update, or disable}
        {id? : Target ID for update or disable}
        {--name= : Human-readable target name}
        {--url= : Starting HTTP or HTTPS URL without credentials}
        {--type= : business or job}
        {--enabled= : 1 to enable or 0 to disable}
        {--max-pages= : Page limit (1-1000; default 25)}
        {--max-depth= : Link depth limit (0-10; default 2)}';

    protected $description = 'Create, list, update, or disable crawl targets without fetching any pages';

    public function handle(): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['list', 'create', 'update', 'disable'], true)) {
            $this->error('Action must be list, create, update, or disable.');

            return self::FAILURE;
        }

        $data = [];

        foreach (['name', 'url', 'type', 'enabled', 'max-pages', 'max-depth'] as $option) {
            $value = $this->option($option);

            if ($value !== null) {
                $key = $option === 'enabled' ? 'is_enabled' : str_replace('-', '_', $option);
                $data[$key] = trim($value);
            }
        }

        if (in_array($action, ['list', 'create'], true) && $this->argument('id') !== null) {
            $this->error('Only update and disable accept a target ID.');

            return self::FAILURE;
        }

        if (in_array($action, ['list', 'disable'], true) && $data !== []) {
            $this->error('Only create and update accept target options.');

            return self::FAILURE;
        }

        if ($action === 'list') {
            $this->table(
                ['ID', 'Name', 'URL', 'Type', 'Enabled', 'Pages', 'Depth'],
                CrawlTarget::orderBy('id')->get()->map(fn (CrawlTarget $target): array => [
                    $target->id,
                    OutputFormatter::escape($target->name),
                    OutputFormatter::escape($target->url),
                    $target->type,
                    $target->is_enabled ? 'yes' : 'no',
                    $target->max_pages,
                    $target->max_depth,
                ]),
            );

            return self::SUCCESS;
        }

        $target = new CrawlTarget;

        if ($action !== 'create') {
            $id = (string) $this->argument('id');

            if (! ctype_digit($id) || (int) $id < 1) {
                $this->error('A positive target ID is required.');

                return self::FAILURE;
            }

            $target = CrawlTarget::find($id);

            if ($target === null) {
                $this->error('Crawl target not found.');

                return self::FAILURE;
            }
        }

        if ($action === 'disable') {
            $target->update(['is_enabled' => false]);
            $this->info("Disabled crawl target {$target->id}.");

            return self::SUCCESS;
        }

        if ($action === 'update' && $data === []) {
            $this->error('Provide at least one target option to update.');

            return self::FAILURE;
        }

        $presence = $action === 'create' ? 'required' : 'sometimes';
        $validator = Validator::make($data, [
            'name' => [$presence, 'filled', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'url' => [$presence, 'filled', 'string', 'max:2048', 'url:http,https', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = parse_url($value);

                if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
                    $fail('The url must not contain credentials.');
                }
            }],
            'type' => [$presence, 'filled', Rule::in(['business', 'job'])],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'max_pages' => ['sometimes', 'required', 'integer', 'between:1,1000'],
            'max_depth' => ['sometimes', 'required', 'integer', 'between:0,10'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $target->fill($validator->validated())->save();
        $verb = $action === 'create' ? 'Created' : 'Updated';
        $this->info("{$verb} crawl target {$target->id}.");

        return self::SUCCESS;
    }
}
