<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;

class RobotsRules
{
    /** @var list<array{allow: bool, pattern: string}> */
    private array $rules = [];

    private float $delay = 0;

    public function __construct(string $text, string $product)
    {
        $groups = [];
        $agents = [];
        $records = [];
        $hasRecords = false;
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'user-agent') {
                if ($hasRecords) {
                    $groups[] = [$agents, $records];
                    $agents = $records = [];
                    $hasRecords = false;
                }

                $agents[] = strtolower($value);
            } elseif ($agents !== [] && in_array($key, ['allow', 'disallow', 'crawl-delay'], true)) {
                $hasRecords = true;
                $records[] = [$key, $value];
            }
        }

        $groups[] = [$agents, $records];
        $bestScore = -1;
        $selected = [];

        foreach ($groups as [$agents, $records]) {
            $score = -1;

            foreach ($agents as $agent) {
                if ($agent === '*') {
                    $score = max($score, 0);
                } elseif ($agent !== '' && str_contains(strtolower($product), $agent)) {
                    $score = max($score, strlen($agent));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $selected = [];
            }

            if ($score >= 0 && $score === $bestScore) {
                $selected = array_merge($selected, $records);
            }
        }

        foreach ($selected as [$key, $value]) {
            if ($key === 'crawl-delay') {
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    throw new FetchException('Invalid robots crawl-delay; fetching paused.');
                }

                $this->delay = max($this->delay, (float) $value);
            } elseif ($value !== '' && str_starts_with($value, '/')) {
                if (strlen($value) > 2048) {
                    throw new FetchException('Robots pattern is too long; fetching paused.');
                }

                $this->rules[] = ['allow' => $key === 'allow', 'pattern' => $this->normalize($value)];
            }
        }
    }

    public function delay(): float
    {
        return $this->delay;
    }

    public function allows(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        $path = $this->normalize($path.($query !== null ? '?'.$query : ''));
        $best = -1;
        $allowed = true;

        foreach ($this->rules as $rule) {
            $pattern = $rule['pattern'];
            $end = str_ends_with($pattern, '$');
            $pattern = $end ? substr($pattern, 0, -1) : $pattern;
            $regex = '~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).($end ? '$' : '').'~D';

            $match = @preg_match($regex, $path);

            if ($match === false) {
                throw new FetchException('Robots pattern could not be evaluated; fetching paused.');
            }

            if ($match === 1) {
                $length = strlen(preg_replace('/%[0-9A-F]{2}/', 'x', str_replace('*', '', $pattern)));

                if ($length > $best || ($length === $best && $rule['allow'])) {
                    $best = $length;
                    $allowed = $rule['allow'];
                }
            }
        }

        return $allowed;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace_callback('/%([0-9a-f]{2})/i', function (array $match): string {
            $character = chr(hexdec($match[1]));

            return preg_match('/[a-zA-Z0-9\-._~]/', $character) ? $character : strtoupper($match[0]);
        }, $value);

        return preg_replace_callback('/[\x80-\xFF]/', fn (array $match): string => sprintf('%%%02X', ord($match[0])), $value);
    }
}
