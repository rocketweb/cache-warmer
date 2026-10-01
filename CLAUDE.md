# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`rocketweb/cache-warmer`: a dependency-free PHP library (PHP >= 8.3, `ext-curl`, `ext-dom`) that warms CDN caches (Fastly, Cloudflare). It sends HEAD requests to check the cache state. If the response is not a cache HIT, it does a full GET. It then parses the page for js/css/img assets and warms them the same way. README.md documents the public API and configuration options.

## Commands

There is no test suite, no build step, and no composer scripts.

- Install autoloader: `composer install`
- Run locally: `php example.php` (gitignored scratch runner that calls `CacheWarmer::run()` against a real site)
- Lint (untracked local phars, no ruleset file in repo): `php phpcs.phar --standard=PSR12 src` and `php phpmd.phar src text cleancode,codesize,design`

## Architecture

Request flow: `CacheWarmer::run()` -> `Processor\Url::processUrls()` -> `Service\Curl` (HTTP) + `Resource\Page` (cache check and HTML parsing).

- `CacheWarmer` is the only public entry point. Its `batchSize` is the concurrency limit (the requests in flight), not a batch size. It creates a new `Url` processor per `run()` call.
- `Processor\Url` is callback driven. `processUrls()` queues every input URL, then calls `Curl::run()` once:
  1. `check()` sends a HEAD. A cache HIT stops there. A 5xx or transport error logs `failed`. Anything else, including 4xx (some origins reject HEAD), goes on to `fetch()`.
  2. `fetch()` sends a GET. A 4xx/5xx logs `failed`. A page body is parsed for elements unless `onlyPages` is set.
  3. An input entry `'/path' => true` skips `check()`. Its elements also skip `check()` (the "invalidate" flag).
  4. Dedup is per run, in `checkedUrls` (HEAD) and `fetchedUrls` (GET). Duplicate elements are not logged.
- `Service\Curl` is a rolling-window scheduler around one `curl_multi` handle (connection reuse, HTTP/2 multiplexing). It keeps `concurrency` requests in flight. Follow-up requests go to a priority queue, so the elements of a page finish before new pages start. Response bodies are kept only for page GETs and are discarded for everything else. Every request sends `CURLOPT_ENCODING => ''` (br, gzip, ...). Fastly caches one object per normalized `Accept-Encoding`, so a request without the header would warm and check a variant browsers never get. Headers are reset at each status line, so after redirects only the final response counts.
- `Service\Response` is a readonly value object: status code, final headers, body, and curl error.
- `Resource\Page::isCached()` merges `DEFAULT_HEADERS` (`x-cache: HIT`, `cf-cache-status: HIT`) with the user header config through `array_merge`. A user key replaces the default values for that header. Matching is a substring match (`str_contains`). `getElements()` uses `Dom\HTMLDocument` on PHP 8.4+ and falls back to `DOMDocument` on 8.3. On 8.4+, a missing attribute returns `null`, not `''`. It reads `script[src]`, `link[href]`, `img[src|data-original|data-hoversrc]`, and `source[src|srcset]`.

## Conventions

- Output is plain `echo` to stdout. `Url::log()` strips the base URL from every line. The line format `URL|Element: (cached|processed|skipped|failed) <url> - <message>` is documented in README.md. Keep it stable.
- Every file uses `<?php declare(strict_types=1);`. PHPMD suppressions are written as `@SuppressWarnings(PHPMD.*)` docblocks.
- If you change constructor or `run()` arguments, update README.md. It is the only user documentation.
