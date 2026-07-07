--TEST--
AOT: DOMDocument::loadHTML + getElementById via DOM JIT bridge (#17130)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadHTML('<html><body><div id="x"></div></body></html>');
$div = $d->getElementById('x');
echo null === $div ? "no_div\n" : "div_ok\n";
--EXPECT--
div_ok
