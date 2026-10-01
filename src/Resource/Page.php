<?php declare(strict_types=1);

namespace RocketWeb\CacheWarmer\Resource;

use Dom\HTMLDocument;
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

    public function getElements(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $dom = $this->createDocument($content);

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
                    $value = $element->getAttribute($attribute) ?? '';
                    if ($attribute == 'srcset') {
                        $values = array_merge($values, $this->getSrcSet($value));
                        continue;
                    }
                    $values[] = $value;
                }
                $finalElements = array_unique(array_merge($finalElements, array_filter(array_map('trim', $values))));
            }
        }

        return $finalElements;
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    private function createDocument(string $content): HTMLDocument|DOMDocument
    {
        if (class_exists(HTMLDocument::class)) {
            return HTMLDocument::createFromString($content, LIBXML_NOERROR);
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        $dom->preserveWhiteSpace = false;

        return $dom;
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
