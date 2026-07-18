<?php
/** Repro #20648 — BcMath\Number arithmetic operators (php-src bcmath_number_do_operation). */
$a = new BcMath\Number('1.5');
$b = new BcMath\Number('2.5');
echo 'op+', (string) ($a + $b), "\n";
echo 'add=', (string) $a->add($b), "\n";
