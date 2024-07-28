<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer;

use RocketWeb\CacheWarmer\Processor\Url;

class CacheWarmer
{
    private int $batchSize;

    public function __construct(int $batchSize = 10)
    {
        $this->batchSize = $batchSize;
    }
    public function run(string $baseUrl, array $urls): void
    {
        $processor = new Url($this->batchSize);

        echo 'Processing URLs - base domain: ' . $processor->getUrl($baseUrl, '') . "\n";

        $batches = array_chunk($urls, $this->batchSize, true);
        $processor->processUrls($baseUrl, $batches);
    }


}