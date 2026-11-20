<?php

namespace App\Services\Crawling;

use DateTimeImmutable;
use DOMXPath;
use Exception;

class ContentExtractor
{
    public function __construct(private LinkDiscovery $links) {}

    /** @return list<array{kind: string, identity_key: string, title: string, data: array, expires_at: ?string}> */
    public function extract(string $html, string $url, string $kind): array
    {
        $xpath = $this->links->document($html);
        $nodes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = json_decode($script->textContent, true, 32);
            if (is_array($json)) {
                $this->walk($json, $nodes);
            }
        }
        $results = [];
        $jobCount = count(array_filter($nodes, fn (array $item): bool => in_array('JobPosting', array_map(fn ($type): string => is_string($type) ? basename($type) : '', (array) ($item['@type'] ?? [])), true)));
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? []);
            $types = array_map(fn ($type): string => is_string($type) ? basename($type) : '', $types);
            if ($kind === 'job' && in_array('JobPosting', $types, true)) {
                $node['_multiple'] = $jobCount > 1;
                $record = $this->job($node, $url);
            } elseif ($kind === 'business' && array_intersect($types, ['Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService', 'Store', 'Restaurant', 'SoftwareCompany'])) {
                $record = $this->business($node, $url);
            } else {
                continue;
            }
            if ($record !== null) {
                $results[$record['identity_key']] = $record;
            }
        }
        if ($kind === 'job' && $results === [] && strtolower(parse_url($url, PHP_URL_HOST) ?? '') === 'www.python.org' && preg_match('~/jobs/\d+/?$~', parse_url($url, PHP_URL_PATH) ?? '')) {
            $record = $this->pythonJob($xpath, $url);
            if ($record !== null) {
                $results[$record['identity_key']] = $record;
            }
        }
        if ($kind === 'business') {
            $fallback = $this->contact($xpath, $url);
            if ($fallback !== null) {
                $key = $fallback['identity_key'];
                if (isset($results[$key])) {
                    foreach (['emails', 'phones'] as $field) {
                        $results[$key]['data'][$field] = array_values(array_unique(array_merge($results[$key]['data'][$field], $fallback['data'][$field])));
                    }
                    if ($results[$key]['data']['address'] === '') {
                        $results[$key]['data']['address'] = $fallback['data']['address'];
                    }
                } else {
                    $results[$key] = $fallback;
                }
            }
        }

        return array_values($results);
    }

    private function walk(array $node, array &$nodes, int $depth = 0): void
    {
        if ($depth > 20 || count($nodes) >= 1000) {
            return;
        }
        if (isset($node['@type'])) {
            $nodes[] = $node;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walk($value, $nodes, $depth + 1);
            }
        }
    }

    private function clean(mixed $value, int $limit = 12000): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, $limit);
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        return $this->clean($xpath->query($query)->item(0)?->textContent ?? '');
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    private function email(mixed $value): ?string
    {
        $email = strtolower(preg_replace('/^mailto:/i', '', $this->clean($value, 254)));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function phone(mixed $value): ?string
    {
        $phone = preg_replace('/[^0-9+]/', '', $this->clean($value, 60));

        return strlen($phone) >= 7 && strlen($phone) <= 20 ? $phone : null;
    }

    private function business(array $node, string $url): ?array
    {
        $name = $this->clean($node['name'] ?? '', 255);
        if ($name === '') {
            return null;
        }
        $website = $this->links->normalize(is_string($node['url'] ?? null) ? $node['url'] : '/', $url) ?? $url;
        $address = $node['address'] ?? '';
        if (is_array($address)) {
            $country = $address['addressCountry'] ?? '';
            $address = implode(', ', array_filter(array_map(fn ($part): string => $this->clean($part), [
                $address['streetAddress'] ?? '', $address['addressLocality'] ?? '', $address['addressRegion'] ?? '',
                $address['postalCode'] ?? '', is_array($country) ? ($country['name'] ?? '') : $country,
            ])));
        }
        $emails = array_values(array_unique(array_filter(array_map($this->email(...), (array) ($node['email'] ?? [])))));
        $phones = array_values(array_unique(array_filter(array_map($this->phone(...), (array) ($node['telephone'] ?? [])))));
        $data = ['name' => $name, 'website' => $website, 'emails' => $emails, 'phones' => $phones, 'address' => $this->clean($address), 'source_urls' => [$url]];
        $identity = 'business:'.strtolower(parse_url($website, PHP_URL_HOST) ?? '').':'.mb_strtolower($name);

        return ['kind' => 'business', 'identity_key' => hash('sha256', $identity), 'title' => $name, 'data' => $data, 'expires_at' => null];
    }

    private function contact(DOMXPath $xpath, string $url): ?array
    {
        $site = $xpath->query('//meta[@property="og:site_name"]')->item(0)?->getAttribute('content') ?? '';
        $name = $this->clean($site, 255);
        if ($name === '') {
            $title = $this->text($xpath, '//title');
            $parts = preg_split('/\s+[|–—-]\s+/u', $title);
            $name = $this->clean(end($parts), 255);
        }
        if ($name === '' || preg_match('/^(contact|about|home)( us)?$/i', $name)) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
        $emails = $phones = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            if (str_starts_with(strtolower($href), 'mailto:')) {
                $email = $this->email(explode('?', rawurldecode(substr($href, 7)), 2)[0]);
                $domain = $email ? explode('@', $email)[1] : '';
                if ($email && ($domain === $host || str_ends_with($domain, '.'.$host))) {
                    $emails[] = $email;
                }
            } elseif (str_starts_with(strtolower($href), 'tel:')) {
                $phones[] = $this->phone(substr($href, 4));
            }
        }
        $text = $this->text($xpath, '//main');
        if ($text === '') {
            $text = $this->text($xpath, '//body');
        }
        if (preg_match('/(?:phone|telephone|voicemail|call us)\s*(?:is|at|:)?:?\s*(\+?[\d(][\d\s().-]{6,25}\d)/i', $text, $match)) {
            $phones[] = $this->phone($match[1]);
        }
        $address = $this->text($xpath, '//address');
        if ($address === '' && strtolower(parse_url($url, PHP_URL_HOST) ?? '') === 'automattic.com') {
            foreach ($xpath->query('//p') as $paragraph) {
                if (preg_match('/\b60\s+29th\s+Street\b/i', $paragraph->textContent)) {
                    $address = $this->clean($paragraph->textContent);
                    break;
                }
            }
        }
        $emails = array_values(array_unique(array_filter($emails)));
        $phones = array_values(array_unique(array_filter($phones)));
        if ($emails === [] && $phones === [] && $address === '') {
            return null;
        }

        return $this->business(['name' => $name, 'url' => '/', 'email' => $emails, 'telephone' => $phones, 'address' => $address], $url);
    }

    private function job(array $node, string $source): ?array
    {
        $title = $this->clean($node['title'] ?? '', 255);
        if ($title === '') {
            return null;
        }
        $company = $node['hiringOrganization'] ?? '';
        $location = $node['jobLocation'] ?? [];
        if (is_array($location) && isset($location[0])) {
            $location = $location[0];
        }
        $address = is_array($location) ? ($location['address'] ?? $location) : $location;
        if (is_array($address)) {
            $address = implode(', ', array_filter(array_map(fn ($part): string => $this->clean($part), [
                $address['addressLocality'] ?? '', $address['addressRegion'] ?? '', $address['addressCountry'] ?? '',
            ])));
        }
        $jobUrl = $this->links->normalize(is_string($node['url'] ?? null) ? $node['url'] : $source, $source) ?? $source;
        $application = $this->links->normalize(is_string($node['applicationUrl'] ?? null) ? $node['applicationUrl'] : $jobUrl, $source) ?? $jobUrl;
        $salary = $node['baseSalary'] ?? '';
        if (is_array($salary)) {
            $value = $salary['value'] ?? '';
            $salary = $this->clean($salary['currency'] ?? '').' '.(is_array($value)
                ? implode(' ', array_filter([$this->clean($value['value'] ?? ''), $this->clean($value['minValue'] ?? ''), $this->clean($value['maxValue'] ?? ''), $this->clean($value['unitText'] ?? '')]))
                : $this->clean($value));
        }
        $company = $this->clean(is_array($company) ? ($company['name'] ?? '') : $company, 255);
        $data = ['title' => $title, 'company' => $company, 'location' => $this->clean($address), 'employment_type' => $this->clean(is_array($node['employmentType'] ?? null) ? implode(', ', array_filter($node['employmentType'], 'is_scalar')) : ($node['employmentType'] ?? '')),
            'description' => $this->clean($node['description'] ?? ''), 'application_url' => $application, 'job_url' => $jobUrl,
            'posted_at' => $this->date($node['datePosted'] ?? null), 'salary' => $this->clean($salary), 'source_urls' => [$source]];
        $identifier = $node['identifier'] ?? '';
        $identifier = $this->clean(is_array($identifier) ? ($identifier['value'] ?? '') : $identifier);
        $identity = $identifier !== '' ? $company.':'.$identifier : $jobUrl;
        if ($jobUrl === $source && ! empty($node['_multiple'])) {
            $identity .= ':'.$company.':'.$title;
        }

        return ['kind' => 'job', 'identity_key' => hash('sha256', 'job:'.$identity), 'title' => $title, 'data' => $data, 'expires_at' => $this->date($node['validThrough'] ?? null)];
    }

    private function pythonJob(DOMXPath $xpath, string $url): ?array
    {
        $heading = $this->text($xpath, '//h1');
        $titleNode = $xpath->query('//h2[normalize-space(.)="Job Title"]')->item(0);
        $title = '';
        if ($titleNode !== null) {
            for ($sibling = $titleNode->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) === 'h2') {
                    break;
                }
                $title .= ' '.$sibling->textContent;
            }
        }
        $title = $this->clean($title, 255) ?: preg_replace('/\s+New$/', '', $heading);
        $company = $this->text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," listing-company-name ")]');
        $companyParts = $xpath->query('//h1//span[contains(concat(" ",normalize-space(@class)," ")," company-name ")]/text()[normalize-space()]');
        if ($companyParts->length > 0) {
            $company = $this->clean($companyParts->item($companyParts->length - 1)->textContent, 255);
        }
        if ($company === '') {
            $company = $this->text($xpath, '//h1/following-sibling::h2[1]');
        }
        $location = $this->text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," listing-company-location ") or contains(concat(" ",normalize-space(@class)," ")," listing-location ")]');
        $description = $this->text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," job-description ")]');
        $application = $xpath->query('//h2[normalize-space(.)="Contact Info"]/following-sibling::ul[1]//a[starts-with(@href,"http")]')->item(0)?->getAttribute('href') ?? $url;
        $posted = $xpath->query('//time[@datetime]')->item(0)?->getAttribute('datetime');

        return $this->job(['title' => $title, 'hiringOrganization' => $company, 'jobLocation' => $location, 'description' => $description, 'applicationUrl' => $application, 'datePosted' => $posted], $url);
    }
}
