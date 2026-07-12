--TEST--
stdlib uri — not advertised on reference profile (#17830, ext/uri)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('uri') ? "fail ext\n" : "ok ext\n";
echo class_exists(\Uri\Rfc3986\Uri::class) ? "fail rfc3986\n" : "ok rfc3986\n";
echo class_exists(\Uri\WhatWg\Url::class) ? "fail whatwg\n" : "ok whatwg\n";
--EXPECT--
ok ext
ok rfc3986
ok whatwg
