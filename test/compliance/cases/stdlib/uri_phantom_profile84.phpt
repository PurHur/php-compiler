--TEST--
stdlib uri withheld under PROFILE=8.4 — Zend 8.5-only (#26254, re-#17830)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('uri'), "\n";
echo 'Rfc=', (int) class_exists('Uri\\Rfc3986\\Uri'), "\n";
echo 'WhatWg=', (int) class_exists('Uri\\WhatWg\\Url'), "\n";
?>
--EXPECT--
ext=0
Rfc=0
WhatWg=0
