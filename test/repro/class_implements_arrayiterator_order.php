<?php
// #25790 — class_implements(ArrayIterator) SeekableIterator-first order
echo implode(',', class_implements('ArrayIterator')), "\n";
echo implode(',', class_implements(new ArrayIterator([]))), "\n";
$r = new ReflectionClass('ArrayIterator');
echo implode(',', $r->getInterfaceNames()), "\n";
