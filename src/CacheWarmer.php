<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer;

use RocketWeb\CacheWarmer\Processor\Url;

class CacheWarmer
{
    private int $batchSize;

    private array $baseUrls = [];

    public function __construct(int $batchSize = 10)
    {
        $this->batchSize = $batchSize;
    }
    public function run(string $baseUrl, array $urls, array $headerConfig = []): void
    {
        $processor = new Url($this->batchSize, $headerConfig);

        echo 'Processing URLs - base domain: ' . $processor->getUrl($baseUrl, '') . "\n";

        $batches = array_chunk($urls, $this->batchSize, true);
        $processor->processUrls($baseUrl, $batches, $this->baseUrls);
    }

    public function setAllowedBaseUrls(array $baseUrls): void
    {
        $this->baseUrls = $baseUrls;
    }
}
