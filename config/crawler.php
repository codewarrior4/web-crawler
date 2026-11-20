<?php

return [
    'user_agent' => 'WebCrawler/0.1 (+https://github.com/codewarrior4/web-crawler)',
    'product_token' => 'WebCrawler',
    'connect_timeout' => 5,
    'request_timeout' => 15,
    'fetch_budget' => 120,
    'max_body_bytes' => 2 * 1024 * 1024,
    'max_robots_bytes' => 512 * 1024,
    'max_redirects' => 3,
    'max_retries' => 2,
    'request_delay' => 2,
    'max_wait' => 60,
    'max_pending_urls' => 100,
    'run_budget' => 600,
    'retention_days' => 30,
    'record_retention_days' => 180,
];
