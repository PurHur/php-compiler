<?php
// AOT: ArrayObject bag float/bool serialize wire (#33687) — Zend d: / b:
$ao = new ArrayObject([1.5, true, false]);
echo serialize($ao), "\n";
