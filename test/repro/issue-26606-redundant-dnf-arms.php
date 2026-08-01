<?php
// Repro for #26606 — equivalent DNF arms must be a compile fatal (Zend wording).
interface A {}
interface B {}
function f((A&B)|(B&A) $x) {}
echo "ok\n";
