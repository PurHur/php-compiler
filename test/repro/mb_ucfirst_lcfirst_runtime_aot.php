<?php

declare(strict_types=1);

/**
 * mb_ucfirst() / mb_lcfirst() runtime (non-literal) args under AOT / NestedJIT (#34259 leftover of #27330).
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (withheld on 8.2 reference profile — #17609 / #22794).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_ucfirst), PHP_FUNCTION(mb_lcfirst)
 */
$s = 'über';
$t = 'Über';
$ascii = 'hello';
$ASCII = 'HELLO';

echo 'uc_var=', mb_ucfirst($s), "\n";
echo 'uc_lit=', mb_ucfirst('über'), "\n";
echo 'uc_ascii=', mb_ucfirst($ascii), "\n";
echo 'lc_var=', mb_lcfirst($t), "\n";
echo 'lc_lit=', mb_lcfirst('Über'), "\n";
echo 'lc_ascii=', mb_lcfirst($ASCII), "\n";
