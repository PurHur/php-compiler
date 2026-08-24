<?php
/**
 * Repro #34549 — AOT echo of SimpleXMLElement attribute prints "Object".
 * (string) cast is correct; echo must use the same baked-text fold (php-src sxe.c).
 * Foreach cast fixed in #34548; this covers echo only.
 */
$x = simplexml_load_string('<r a="1"><c id="2">t</c></r>');
echo $x['a'], "\n";
echo (string) $x['a'], "\n";
echo $x->c['id'], "\n";
