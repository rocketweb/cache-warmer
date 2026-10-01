<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer\Processor;

use RocketWeb\CacheWarmer\Resource\Page;
use RocketWeb\CacheWarmer\Service\Curl;
use RocketWeb\CacheWarmer\Service\Response;

class Url
{
    private const TYPE_URL = 'URL';
    private const TYPE_ELEMENT = 'Element';

    private Curl $curl;
    private Page $page;
    private string $baseUrl = '';
    private array $allowedBaseUrls = [];
    private array $checkedUrls = [];
    private array $fetchedUrls = [];

    public function __construct(int $batchSize, array $headerConfig = [], private readonly bool $onlyPages = false)
    {
        $this->curl = new Curl($batchSize);
        $this->page = new Page($headerConfig);
    }

    public function processUrls(string $baseUrl, array $urls, array $alternativeBaseUrls): void
    {
        $this->baseUrl = $baseUrl;
        $this->allowedBaseUrls = [...$alternativeBaseUrls, $baseUrl];

        foreach ($urls as $url => $value) {
            if ($value === true) {
                $this->fetch(self::TYPE_URL, $this->getUrl($baseUrl, (string)$url), true, false);
                continue;
            }
            // If second parameter is bool, then first parameter is URL, otherwise second parameter is URL
            $url = is_bool($value) ? $url : $value;
            $this->check(self::TYPE_URL, $this->getUrl($baseUrl, (string)$url), false);
        }

        $this->curl->run();
    }

    public function getUrl(string $baseUrl, string $url): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function check(string $type, string $url, bool $priority): void
    {
        if (isset($this->checkedUrls[$url])) {
            $this->logDuplicate($type, 'cached', $url, $type . ' already warmed-up, skipping it');
            return;
        }

        $this->checkedUrls[$url] = true;
        $this->curl->add($url, true, false, fn (Response $response) => $this->onChecked($type, $response), $priority);
    }

    private function onChecked(string $type, Response $response): void
    {
        if ($response->isServerFailure()) {
            $this->log($type, 'failed', $response->url, $response->getFailureReason());
            return;
        }

        if ($this->page->isCached($response->headers)) {
            $this->log($type, 'cached', $response->url, $type . ' is cached, skipping warm-up');
            return;
        }

        $this->fetch($type, $response->url, false, true);
    }

    private function fetch(string $type, string $url, bool $invalidate, bool $priority): void
    {
        if (isset($this->fetchedUrls[$url])) {
            $this->logDuplicate($type, 'skipped', $url, $type . ' already fetched, skipping');
            return;
        }

        $this->fetchedUrls[$url] = true;
        $keepBody = $type === self::TYPE_URL && !$this->onlyPages;
        $this->curl->add(
            $url,
            false,
            $keepBody,
            fn (Response $response) => $this->onFetched($type, $response, $invalidate),
            $priority
        );
    }

    private function onFetched(string $type, Response $response, bool $invalidate): void
    {
        if ($response->isFailed()) {
            $this->log($type, 'failed', $response->url, $response->getFailureReason());
            return;
        }

        $this->log($type, 'processed', $response->url, $type . ' warmed-up');
        if ($type === self::TYPE_ELEMENT || $this->onlyPages) {
            return;
        }

        foreach ($this->getElements($response->body) as $element) {
            if ($invalidate) {
                $this->fetch(self::TYPE_ELEMENT, $element, true, true);
                continue;
            }
            $this->check(self::TYPE_ELEMENT, $element, true);
        }
    }

    private function getElements(string $content): array
    {
        $elements = [];
        foreach ($this->page->getElements($content) as $element) {
            $urlParts = parse_url($element);
            if (!isset($urlParts['host'])) {
                $elements[] = $this->getUrl($this->baseUrl, $element);
                continue;
            }

            foreach ($this->allowedBaseUrls as $baseUrl) {
                if (str_starts_with($element, $baseUrl)) {
                    $elements[] = $element;
                    break;
                }
            }
        }

        return array_unique($elements);
    }

    private function logDuplicate(string $type, string $state, string $url, string $message): void
    {
        if ($type === self::TYPE_ELEMENT) {
            return;
        }

        $this->log($type, $state, $url, $message);
    }

    private function log(string $type, string $state, string $url, string $message): void
    {
        $logMessage = sprintf("%s: (%s) %s - %s\n", $type, $state, $url, $message);
        echo str_replace(rtrim($this->baseUrl, '/'), '', $logMessage);
    }
}
