<?php
// Repro for #26787: AOT SplObjectStorage object-key offset — TypeError Illegal offset type
// Expected: prints "1" then "x" (matches Zend/VM/JIT)
$s = new SplObjectStorage();
$o = new stdClass();
$s[$o] = 'x';
echo $s->count(), "\n";
echo $s[$o], "\n";
