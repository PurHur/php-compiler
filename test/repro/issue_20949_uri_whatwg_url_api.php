<?php
// Repro for #20949 — Uri\WhatWg\Url withers / resolve / UrlValidationError
// Phantoms isSpecialScheme/getHostType/UrlHostType retired for php-src-strict (#28199)
declare(strict_types=1);

$u = Uri\WhatWg\Url::parse('https://user:pass@example.com:8443/a/b?q=1#f');
foreach ([
    'withScheme', 'withHost', 'withPath', 'withPort', 'withUsername', 'withPassword',
    'isSpecialScheme', 'getHostType', 'resolve', 'withQuery', 'equals',
] as $m) {
    echo $m, '=', method_exists($u, $m) ? 'yes' : 'no', PHP_EOL;
}
echo 'UrlHostType=', enum_exists('Uri\\WhatWg\\UrlHostType') ? 'yes' : 'no', PHP_EOL;
echo 'UrlValidationError=', class_exists('Uri\\WhatWg\\UrlValidationError') ? 'yes' : 'no', PHP_EOL;
echo 'UrlValidationErrorType=', enum_exists('Uri\\WhatWg\\UrlValidationErrorType') ? 'yes' : 'no', PHP_EOL;

$u2 = $u->withScheme('http')->withHost('example.org')->withPath('/z')->withPort(80)->withUsername('a')->withPassword('b');
echo 'mut=', $u2->toAsciiString(), PHP_EOL;
echo 'orig=', $u->toAsciiString(), PHP_EOL;

$rel = $u->resolve('../c?x=1');
echo 'resolve=', $rel->getPath(), '?', $rel->getQuery(), PHP_EOL;
