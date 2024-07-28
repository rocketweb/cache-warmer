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