<?php
/** Maintainer gap: ArrayObject/ArrayIterator::__construct(null $flags) missing E_DEPRECATED (ext/spl/spl_array.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$o = new ArrayObject([1], null);
echo 'ArrayObject flags=' . $o->getFlags() . "\n";
$i = new ArrayIterator([1], null);
echo 'ArrayIterator flags=' . $i->getFlags() . "\n";
