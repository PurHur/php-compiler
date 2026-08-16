<?php
/** Maintainer gap: SplFixedArray::__construct(null) deprecation param index #0 vs Zend #1 (ext/spl/spl_fixedarray.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$a = new SplFixedArray(null);
echo 'size=' . $a->getSize() . "\n";
