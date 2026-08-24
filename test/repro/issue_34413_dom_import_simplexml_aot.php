<?php

declare(strict_types=1);

// #34413 — AOT dom_import_simplexml after simplexml_load_string (php-src ext/dom/node.c)
$x = simplexml_load_string('<r a="1"><c/></r>');
$d = dom_import_simplexml($x);
echo $d->nodeName, ':', $d->getAttribute('a'), "\n";
echo "DONE\n";
