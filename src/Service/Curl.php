<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer\Service;

class Curl
{
    private array $preFetchCache = [];
    private array $fetchCache = [];

    public function preFetchBatch(array $urls): array
    {
        $multiHandler = curl_multi_init();
        $headerData = [];
        foreach ($urls as $url) {
            if (in_array($url, $this->preFetchCache, true)) {
                $headerData[$url] = null;
                continue;
            }

            $curlHandler = curl_init();

            curl_setopt($curlHandler, CURLOPT_URL, $url);
            curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, 'HEAD');
            #curl_setopt($curlHandler, CURLINFO_HEADER_OUT, true); // Needed for debugging purposes only
            curl_setopt($curlHandler, CURLOPT_HEADER, true);
            curl_setopt($curlHandler, CURLOPT_NOBODY, true);
            curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($curlHandler, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curlHandler, CURLOPT_TIMEOUT, 10);
            curl_setopt($curlHandler, CURLOPT_HEADERFUNCTION,
                function($handler, $header) use (&$headerData)
                {
                    //TODO: Confirm that headers are correct for 301 redirects!
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) {
                        return $len;
                    }
                    $url = curl_getinfo($handler, CURLINFO_EFFECTIVE_URL);

                    $headerData[$url][strtolower(trim($header[0]))] = trim($header[1]);

                    return $len;
                }
            );

            curl_multi_add_handle($multiHandler, $curlHandler);
        }

        do {
            curl_multi_exec($multiHandler, $running);
            curl_multi_select($multiHandler);
        } while ($running > 0);

        // We already have the headers thru HEADERFUNCTION callback, nothing else to do!
        curl_multi_close($multiHandler);

        $this->preFetchCache = array_merge($this->preFetchCache, array_keys($headerData));

        return $headerData;
    }

    public function fetchBatch(array $urls): array
    {
        $handlerData = [];
        $contentData = [];

        $multiHandler = curl_multi_init();

        foreach ($urls as $url) {
            if (in_array($url, $this->fetchCache, true)) {
                $contentData[$url] = null;
                continue;
            }
            $curlHandler = curl_init();

            curl_setopt($curlHandler, CURLOPT_URL, $url);
            curl_setopt($curlHandler, CURLOPT_HEADER, false);
            curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($curlHandler, CURLOPT_TIMEOUT, 10);

            $handlerData[$url] = $curlHandler;

            curl_multi_add_handle($multiHandler, $curlHandler);
        }

        do {
            curl_multi_exec($multiHandler, $running);
            curl_multi_select($multiHandler);
        } while ($running > 0);

        foreach ($handlerData as $url => $handler) {
            $contentData[$url] = curL_multi_getcontent($handler);
        }

        curl_multi_close($multiHandler);
        $this->fetchCache = array_merge($this->fetchCache, array_keys($handlerData));

        return $contentData;
    }
}