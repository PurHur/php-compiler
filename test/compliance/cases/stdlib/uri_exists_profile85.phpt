--TEST--
stdlib uri advertised under PROFILE=8.5 — Zend 8.5 parity (#26254)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('uri'), "\n";
echo 'Rfc=', (int) class_exists('Uri\\Rfc3986\\Uri'), "\n";
echo 'WhatWg=', (int) class_exists('Uri\\WhatWg\\Url'), "\n";
?>
--EXPECT--
ext=1
Rfc=1
WhatWg=1
