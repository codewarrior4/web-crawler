<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;

class DnsResolver
{
    /** @return list<string> */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            throw new FetchException('DNS lookup failed.');
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
