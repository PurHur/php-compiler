<?php
/** Issue #19582 — BcMath\Number pow/mod/sqrt/floor/ceil/round. */
$n = new BcMath\Number('2');
foreach (['add', 'pow', 'mod', 'sqrt', 'floor', 'ceil', 'round'] as $m) {
    echo 'has_'.$m.'=', method_exists($n, $m) ? '1' : '0', "\n";
}
echo 'pow=', (string) (new BcMath\Number('2'))->pow('8'), "\n";
echo 'mod=', (string) (new BcMath\Number('10'))->mod('3'), "\n";
echo 'sqrt=', (string) (new BcMath\Number('9'))->sqrt(), "\n";
echo 'floor=', (string) (new BcMath\Number('1.5'))->floor(), "\n";
echo 'ceil=', (string) (new BcMath\Number('1.5'))->ceil(), "\n";
echo 'round=', (string) (new BcMath\Number('1.5'))->round(0), "\n";
