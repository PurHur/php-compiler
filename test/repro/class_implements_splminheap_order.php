<?php
// #25822 — SplMinHeap/SplMaxHeap class_implements Countable-first order
echo 'SplHeap:', implode(',', class_implements('SplHeap')), "\n";
echo 'SplMinHeap:', implode(',', class_implements('SplMinHeap')), "\n";
echo 'SplMaxHeap:', implode(',', class_implements('SplMaxHeap')), "\n";
$r = new ReflectionClass('SplMinHeap');
echo 'SplMinHeap refl:', implode(',', $r->getInterfaceNames()), "\n";
$r = new ReflectionClass('SplMaxHeap');
echo 'SplMaxHeap refl:', implode(',', $r->getInterfaceNames()), "\n";
