<?php

declare(strict_types=1);

// Repro #34413 — AOT must lower dom_import_simplexml after simplexml_load_string.
$x = simplexml_load_string('<r a="1"><c/></r>');
$el = dom_import_simplexml($x);
echo $el->nodeName, ':', $el->getAttribute('a'), "\n";
