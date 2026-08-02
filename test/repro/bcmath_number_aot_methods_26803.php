<?php
// AOT BcMath\Number::{add,mul,compare} under PROFILE=8.4 (#26803)
// Expect: 4.00 / 2.46 / 0
$n = new BcMath\Number("1.23");
echo (string) $n->add("2.77"), "\n";
echo (string) $n->mul(2), "\n";
echo (string) $n->compare("1.230"), "\n";
