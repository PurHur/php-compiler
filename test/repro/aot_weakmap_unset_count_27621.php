<?php

declare(strict_types=1);

// AOT WeakMap count after referent unset (#27621 / Zend/zend_weakrefs.c).
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 42;
echo $wm[$o], "\n";
unset($o);
echo count($wm), "\n";
