<?php declare(strict_types=1);

namespace RocketWeb\CacheWarmer\Resource;

use DOMDocument;

class Page
{
    private const DEFAULT_HEADERS = [
        'x-cache' => ['HIT'],
        'cf-cache-status' => ['HIT']
    ];

    private array $cacheHeaders;
    public function __construct(array $headerConfig = [])
    {
        $this->cacheHeaders = array_merge(self::DEFAULT_HEADERS, $headerConfig);
    }
    public function isCached(array $headers): bool
    {
        foreach ($this->cacheHeaders as $header => $values) {
            if (isset($headers[$header])) {
                foreach ($values as $value) {
                    if (str_contains($headers[$header], $value)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function getElements(string $content): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        $dom->preserveWhiteSpace = false;

        $tags = [
            'script' => ['src'],
            'link' => ['href'],
            'img' => ['src', 'data-original', 'data-hoversrc'],
            'source' => ['src', 'srcset'],
        ];

        $finalElements = [];
        foreach ($tags as $tagName => $tagAttributes) {
            $elements = $dom->getElementsByTagName($tagName);
            foreach ($elements as $element) {
                $values = [];
                foreach ($tagAttributes as $attribute) {
                    if ($attribute == 'srcset') {
                        $values = array_merge($values, $this->getSrcSet($element->getAttribute('srcset')));
                        continue;
                    }
                    $values[] = $element->getAttribute($attribute);
                }
                $finalElements = array_unique(array_merge($finalElements, array_filter(array_map('trim', $values))));
            }
        }

        return $finalElements;
    }

    private function getSrcSet(string $srcset): array
    {
        if (empty($srcset)) {
            return [];
        }

        $srcsets = explode(',', $srcset);
        $srcsets = array_map(function ($data) {
            $data = array_filter(explode(' ', trim($data)));
            return trim($data[0]);
        }, $srcsets);

        return array_filter($srcsets);
    }
}
