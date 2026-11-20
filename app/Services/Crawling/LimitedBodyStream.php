<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

class LimitedBodyStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(private int $limit)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > $this->limit) {
            throw new FetchException('Response exceeds the configured size limit.');
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }
}
