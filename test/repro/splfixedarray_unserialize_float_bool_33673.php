<?php
// AOT: SplFixedArray unserialize float/bool from Zend wire (#33673).
// Keep ≤3 unserialize sites in one TU — four+ currently SEGVs under NestedJIT.
$f = unserialize('O:13:"SplFixedArray":1:{i:0;d:1.5;}');
echo $f->getSize(), ':', json_encode(iterator_to_array($f)), "\n";
$b = unserialize('O:13:"SplFixedArray":1:{i:0;b:1;}');
echo $b->getSize(), ':', json_encode(iterator_to_array($b)), "\n";
$m = unserialize('O:13:"SplFixedArray":2:{i:0;d:1.5;i:1;b:1;}');
echo $m->getSize(), ':', json_encode(iterator_to_array($m)), "\n";
