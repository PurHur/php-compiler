<?php
// AOT: SplFixedArray float/bool serialize wire (#33682) — Zend d: / b: (#33520 JIT tags).
$a = SplFixedArray::fromArray([1.5, true, false]);
echo serialize($a), "\n";
$b = [1.5, true];
echo serialize($b), "\n";
