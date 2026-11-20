<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateCrawlerUser extends Command
{
    protected $signature = 'crawler:user {email} {--name=Operator} {--password-stdin : Read a new password from standard input instead of a hidden prompt}';

    protected $description = 'Create a trusted crawler operator account (no public registration)';

    public function handle(): int
    {
        $password = $this->option('password-stdin') ? rtrim(stream_get_contents(STDIN), "\r\n") : $this->secret('New password (at least 12 characters)');
        $data = ['email' => strtolower(trim($this->argument('email'))), 'name' => $this->option('name'), 'password' => $password];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'max:255', 'unique:users,email'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:200']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($validator->validated());
        $user->is_operator = true;
        $user->save();
        $this->info('Operator created. Sign in with the supplied email and password.');

        return self::SUCCESS;
    }
}
