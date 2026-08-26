<?php
/** Repro for #35180 — createAttributeNS on empty document requires documentElement. */
$d = new DOMDocument();
$a = $d->createAttributeNS('urn:x', 'x:id');
echo false === $a ? 'false' : get_debug_type($a);
echo "\n";
