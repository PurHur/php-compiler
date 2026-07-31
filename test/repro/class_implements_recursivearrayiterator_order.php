<?php
// #25796 — class_implements(RecursiveArrayIterator) Countable-first order
echo implode(',', class_implements('RecursiveArrayIterator')), "\n";
echo implode(',', class_implements(new RecursiveArrayIterator([]))), "\n";
$r = new ReflectionClass('RecursiveArrayIterator');
echo implode(',', $r->getInterfaceNames()), "\n";
