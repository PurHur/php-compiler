<?php
/** Repro #27286 — ArrayObject `$o[]=` must append into `__spl_ht`, not clobber the object. */
$o = new ArrayObject([1, 2, 3]);
$o[] = 4;
echo count($o), '|', $o[3], "\n";
