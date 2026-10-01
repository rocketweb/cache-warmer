<?php declare(strict_types=1);
namespace RocketWeb\CacheWarmer\Service;

readonly class Response
{
    public function __construct(
        public string $url,
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $error
    ) {
    }

    public function isFailed(): bool
    {
        return $this->error !== '' || $this->statusCode >= 400;
    }

    public function isServerFailure(): bool
    {
        return $this->error !== '' || $this->statusCode >= 500;
    }

    public function getFailureReason(): string
    {
        return $this->error !== '' ? $this->error : 'HTTP ' . $this->statusCode;
    }
}
