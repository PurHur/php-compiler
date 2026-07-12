<?php

// Non-strict caller: null coerces to empty string → false (#18215, re-#14598).
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"/>');
$root = $doc->documentElement;
echo (int) $root->isDefaultNamespace('http://example.com'), "\n";
echo (int) $root->isDefaultNamespace(null), "\n";
echo "ok\n";
