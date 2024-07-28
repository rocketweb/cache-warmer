<?php declare(strict_types=1);

namespace RocketWeb\FastlyCacheWarmer\Processor;

use RocketWeb\FastlyCacheWarmer\Resource\Page;
use RocketWeb\FastlyCacheWarmer\Service\Curl;

class Url
{
    private Curl $curl;
    private Page $page;
    private int $batchSize;

    private string $baseUrl;

    public function __construct(int $batchSize)
    {
        $this->batchSize = $batchSize;
        $this->curl = new Curl();
        $this->page = new Page();
    }

    public function processUrls(string $baseUrl, array $batches): void
    {
        $this->baseUrl = $baseUrl;
        $processFurther = [];
        $batchCounter = 0;
        foreach ($batches as $urlBatch) {

            $data = [];
            foreach ($urlBatch as $url => $value) {
                if ($value === true) {
                    $processFurther[$this->getUrl($baseUrl, $url)] = true;
                    continue;
                }
                // If second parameter is bool, then first parameter is URL, otherwise second parameter is URL
                $url = is_bool($value) ? $url : $value;
                $data[] = $this->getUrl($baseUrl, $url);
            }
            unset($urlBatch);

            /**
             * We prefetch headers (using HEAD) for URLs that were not forced to be invalid. If header test fails,
             * we process it further, otherwise nothing needed - site is cached &
             */
            $urlHeaders = $this->curl->preFetchBatch($data);
            foreach ($urlHeaders as $finalUrl => $headers) {
                if ($headers === null) {
                    // URL was already checked, skipping
                    $this->log('(%s) %s - URL already warmed-up, skipping it', 'cached', $finalUrl);
                    continue;
                }

                if ($this->page->isCached($headers)) {
                    $this->log('(%s) %s - URL is cached, skipping warm-up for it', 'cached', $finalUrl);
                    continue;
                }

                $processFurther[$finalUrl] = false;
            }

            $batchCounter++;
            while (count($processFurther) >= $this->batchSize
                || count($batches) === $batchCounter && count($processFurther) > 0
            ) {
                $this->log('Processing batch of URLs ...');
                $this->processElementsBatch(array_splice($processFurther, 0, $this->batchSize, []), $baseUrl);
                $this->log('... Batch completed!');
            }

        }

    }

    public function processElementsBatch(array $urlBatch, string $baseUrl): void
    {
        $contents = $this->curl->fetchBatch(array_keys($urlBatch));
        $finalElements = [];
        foreach ($contents as $url => $content) {
            if ($content === null) {
                $this->log('URL: (%s) %s - URL already fetched, skipping!', 'skipped', $url);
                continue;
            }

            $this->log('URL: (%s) %s - URL warmed up!', 'processed', $url);
            $elements = $this->page->getElements($content);
            $elements = array_filter($elements, function ($element) use ($baseUrl) {
                return str_starts_with($element, $baseUrl);
            });

            // We set the value of array to "invalidate" option of the parent URL
            foreach ($elements as $element) {
                $finalElements[$element] = !empty($finalElements[$element]) ?: $urlBatch[$url];
            }
        }

        $processFurther = [];
        $elementsForPreFetch = [];
        foreach ($finalElements as $element => $invalidate) {
            switch ($invalidate) {
                case true:
                    $processFurther[] = $element;
                    break;
                default:
                    $elementsForPreFetch[] = $element;
                    break;
            }
        }
        unset($finalElements);

        if (count($elementsForPreFetch) == 0 && count($processFurther) == 0) {
            return;
        }

        $this->log('Processing Elements of the URL batch ...');
        while (count($elementsForPreFetch) > 0) {
            $urlHeaders = $this->curl->preFetchBatch(array_splice($elementsForPreFetch, 0, $this->batchSize, []));
            foreach ($urlHeaders as $finalUrl => $headers) {
                if ($headers === null) {
                    // URL was already checked, skipping
                    $this->log('Element: (%s) %s - Element already warmed-up, skipping it!', 'cached', $finalUrl);
                    continue;
                }

                if ($this->page->isCached($headers)) {
                    $this->log('Element: (%s) %s - Element is cached, skipping warm-up!', 'cached', $finalUrl);
                    continue;
                }

                $processFurther[] = $finalUrl;
            }

        }


        foreach (array_chunk($processFurther, $this->batchSize, true) as $urlBatch) {
            // We don't care about the content here, we are just fetching elements to get loaded into CDN
            $skipped = array_filter($this->curl->fetchBatch($urlBatch), function ($content) {
                return $content === null;
            });
            $skipped = array_keys($skipped);

            array_walk($urlBatch, function ($finalUrl) use ($baseUrl, $skipped) {
                $message = in_array($finalUrl, $skipped, true) ?
                    'Element already fetched, skipping!' : 'Element warmed-up!';
                $status = in_array($finalUrl, $skipped, true) ? 'skipped' : 'processed';

                $this->log('Element: (%s) %s - %s', $status, $finalUrl, $message);
            });
        }
    }

    public function getUrl(string $baseUrl, string $url): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function log(string $message, ...$arguments): void
    {
        $logMessage = sprintf($message . "\n", ...$arguments);
        echo str_replace(rtrim($this->baseUrl, '/'), '', $logMessage);
    }

}