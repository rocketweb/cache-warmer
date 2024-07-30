# Cache Warmer (for Fastly CDN)

## Info
This library allows for faster Cache Warmup when dealing with CDN (Fastly CDN & Cloudflare supported so far). It uses 
HEAD request to check for 
Cache status. If Cache Status is not a HIT, then full page load is executed which is then parsed for css/js/img 
elements and all those get requested also!

It supports smart caching, so you are not requesting the same Page/Element over and over again!

## Installation & Usage
This library can be installed using composer:
```
composer require rocketweb/cache-warmer
```

Once installed, you can load it up using standard PSR-4 call. Example code:
```
<?php
require 'vendor/autoload.php'; // If using directly in a file!

$cacheWarmer = new \RocketWeb\CacheWarmer\CacheWarmer();
$cacheWarmer->run(
    'https://domain.com', 
    [
        '/url1.html',
        '/url2.html',
        ...
    ]
);
```
The output is echoed directly and contains the following information:
```
URL|Element: (cached|processed|skipped) %URL% - %message%

URL|Element => indicates what is being processed.
  - URL is a Page URL which was provided in the array.
  - Element is js/css/img information that was parsed out from the Page.
cached|processed|skipped => indicates the state.
  - cached - the HEAD request returned the proper Cache Header value
  - processed - the Cache Header failed, the Page/Element was fetched (loaded)
  - skipped - the Page/Element was already requested before (duplicate)
```


## Configuration
There are few things that can be configured:
1. you can set how many concurrent requests you want to execute to the server (applies to both Pages & Elements)
```
$batchSize = 20;
$cacheWarmer = new \RocketWeb\CacheWarmer\CacheWarmer($batchSize);
```
2. You can bypass the CDN Cache result for certain URLs which will force the Page to be loaded & all Elements to be 
   fetched!
```
$cacheWarmer->run(
    'https://domain.com', 
    [
        '/url1.html' => true,  // setting the value as true will skipp the CDN Cache validation and do full load!
        '/url2.html',
        ...
    ]
);
```
3. You can set up Alternative domains to be used when processing Elements on the Page (for example - your media is 
   being served from different domain). You need to set that before calling `->run()`:
```
$cacheWarmer->setAllowedBaseUrls([
    'https://cdn.something.net/',
    'https://media.somethingelse.net/'
]);
$cacheWarmer->run( ...
```
4. You can specify custom header & value to be matched against for CDN Caching validation. The validation is done thru 
   partial match (needle in haystack). You need to pass additional configuration into the `->run()` method:
```
$cacheWarmer->run(
    'https://domain.com',
    [
        ... // URLs to check
    ],
    [
         'header_key' => ['value', ...]
    ]
);
```

The configuration gets merged together with default values (that support Fastly & Cloudflare):
```
   \RocketWeb\CacheWarmer\Resource\Page

    private const DEFAULT_HEADERS = [
        'x-cache' => ['HIT'],
        'cf-cache-status' => ['HIT']
    ];
```

For example, if you want to include **DYNAMIC** to be passed as Cached for Cloudflare:
```
$cacheWarmer->run(
    'https://domain.com',
    [
        '/url1.html',
        '/url2.html',
        ...
    ],
    [
        'cf-cache-status' => ['HIT', 'DYNAMIC']
    ]);
```