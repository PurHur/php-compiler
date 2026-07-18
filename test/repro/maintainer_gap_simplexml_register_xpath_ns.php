<?php

declare(strict_types=1);

// Issue #20334 — registerXPathNamespace must apply to subsequent xpath() (ext/simplexml/simplexml.c).

$x = simplexml_load_string('<r xmlns:a="http://a"><a:x/></r>');
$x->registerXPathNamespace('p', 'http://a');
$n = $x->xpath('//p:x');
echo 'count=', count($n), "\n";
