<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer\Service;

use CurlHandle;
use CurlMultiHandle;
use SplQueue;

class Curl
{
    private const TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;
    private const MAX_REDIRECTS = 5;
    private const SELECT_TIMEOUT = 1.0;

    private CurlMultiHandle $multiHandle;
    private SplQueue $priorityQueue;
    private SplQueue $queue;
    private array $requests = [];
    private array $headers = [];

    public function __construct(private readonly int $concurrency)
    {
        $this->multiHandle = curl_multi_init();
        curl_multi_setopt($this->multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt($this->multiHandle, CURLMOPT_MAX_HOST_CONNECTIONS, $concurrency);

        $this->priorityQueue = new SplQueue();
        $this->queue = new SplQueue();
    }

    public function add(string $url, bool $headOnly, bool $keepBody, callable $callback, bool $priority = false): void
    {
        $request = [$url, $headOnly, $keepBody, $callback];
        if ($priority) {
            $this->priorityQueue->enqueue($request);
            return;
        }

        $this->queue->enqueue($request);
    }

    public function run(): void
    {
        do {
            $this->fillWindow();

            curl_multi_exec($this->multiHandle, $running);
            while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
                $this->complete($info['handle'], $info['result']);
            }

            if ($running > 0 && curl_multi_select($this->multiHandle, self::SELECT_TIMEOUT) === -1) {
                usleep(1000);
            }
        } while ($this->requests !== [] || !$this->priorityQueue->isEmpty() || !$this->queue->isEmpty());
    }

    private function fillWindow(): void
    {
        while (count($this->requests) < $this->concurrency) {
            $queue = $this->priorityQueue->isEmpty() ? $this->queue : $this->priorityQueue;
            if ($queue->isEmpty()) {
                return;
            }

            $this->start(...$queue->dequeue());
        }
    }

    private function start(string $url, bool $headOnly, bool $keepBody, callable $callback): void
    {
        $handle = curl_init();
        $id = spl_object_id($handle);
        $this->headers[$id] = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_PIPEWAIT => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_CONTENT_DECODING => $keepBody,
            CURLOPT_HEADERFUNCTION => fn (CurlHandle $handle, string $line): int => $this->collectHeader($id, $line),
        ]);

        if ($keepBody) {
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        } else {
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, fn (CurlHandle $handle, string $data): int => strlen($data));
        }

        curl_multi_add_handle($this->multiHandle, $handle);
        $this->requests[$id] = [$handle, $url, $callback];
    }

    private function collectHeader(int $id, string $header): int
    {
        $length = strlen($header);
        if (str_starts_with($header, 'HTTP/')) {
            $this->headers[$id] = [];
            return $length;
        }

        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $this->headers[$id][strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return $length;
    }

    private function complete(CurlHandle $handle, int $result): void
    {
        $id = spl_object_id($handle);
        [, $url, $callback] = $this->requests[$id];

        $response = new Response(
            $url,
            (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $this->headers[$id],
            (string)curl_multi_getcontent($handle),
            $result === CURLE_OK ? '' : curl_strerror($result)
        );

        curl_multi_remove_handle($this->multiHandle, $handle);
        unset($this->requests[$id], $this->headers[$id]);

        $callback($response);
    }
}
