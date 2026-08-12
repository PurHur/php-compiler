<?php
// #28197 — Uri ASCII host lowercasing (php-src ext/uri)

$a = Uri\WhatWg\Url::parse('https://EXAMPLE.com/a');
$b = Uri\WhatWg\Url::parse('https://example.com/a');
echo $a->getAsciiHost(), "\n";
echo $a->toAsciiString(), "\n";
echo $a->equals($b) ? 'eq' : 'ne', "\n";
$r = Uri\Rfc3986\Uri::parse('https://EXAMPLE.com/a');
echo $r->getHost(), "\n";
echo $r->getRawHost(), "\n";
