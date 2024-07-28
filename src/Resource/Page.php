<?php declare(strict_types=1);

namespace RocketWeb\CacheWarmer\Resource;

use DOMDocument;

class Page
{
    public function isCached(array $headers): bool
    {
        return (isset($headers['x-cache']) && str_contains($headers['x-cache'], 'HIT'))
            || (isset($headers['cf-cache-status']) && str_contains($headers['cf-cache-status'], 'HIT'));
    }

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
        $srcsets = array_map(function($data) {
            $data = array_filter(explode(' ', trim($data)));
            return trim($data[0]);
        }, $srcsets);

        return array_filter($srcsets);
    }
}