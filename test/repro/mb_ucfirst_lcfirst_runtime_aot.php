<?php

declare(strict_types=1);

/**
 * mb_ucfirst() / mb_lcfirst() runtime (non-literal) args under AOT NestedJIT (#34259).
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (php-src 8.4+ builtins).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_ucfirst), PHP_FUNCTION(mb_lcfirst)
 */
$s = 'über';
$t = 'Über';
$ascii = 'hello';

echo 'uc_var=', mb_ucfirst($s), "\n";
echo 'uc_lit=', mb_ucfirst('über'), "\n";
echo 'uc_ascii=', mb_ucfirst($ascii), "\n";
echo 'lc_var=', mb_lcfirst($t), "\n";
echo 'lc_lit=', mb_lcfirst('Über'), "\n";
