<?php
// Repro for #20541 — Uri\WhatWg\Url API surface
declare(strict_types=1);

$u = Uri\WhatWg\Url::parse('https://user:pass@example.com:8443/a?q=1#f');
foreach (['getQuery', 'getFragment', 'getPort', 'getUsername', 'getPassword', 'getUnicodeHost', 'toUnicodeString', 'equals', 'withQuery'] as $m) {
    echo $m, '=', method_exists($u, $m) ? '1' : '0', PHP_EOL;
}
echo 'query=', $u->getQuery(), PHP_EOL;
echo 'frag=', $u->getFragment(), PHP_EOL;
echo 'port=', $u->getPort(), PHP_EOL;
echo 'user=', $u->getUsername(), PHP_EOL;
echo 'uni=', $u->toUnicodeString(), PHP_EOL;
$u2 = $u->withQuery('x=1');
echo 'with=', $u2->getQuery(), PHP_EOL;
