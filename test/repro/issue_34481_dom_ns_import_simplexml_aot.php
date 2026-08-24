<?php

declare(strict_types=1);

// #34481 — AOT Dom\import_simplexml after simplexml_load_string (php-src ext/dom/php_dom.c)
// Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ namespace).
$x = simplexml_load_string('<root id="1"><item>a</item></root>');
$d = Dom\import_simplexml($x);
echo get_class($d), "\n";
echo $d->nodeName, ':', $d->getAttribute('id'), "\n";
echo "DONE\n";
