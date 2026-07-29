<?php
// Repro for #24681: AOT WeakMap object offset — TypeError Illegal offset type
// Expected: prints "1" (matches Zend/VM/JIT)
$wm = new WeakMap();
$o = new stdClass;
$wm[$o] = 1;
echo $wm[$o];
