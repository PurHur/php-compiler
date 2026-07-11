--TEST--
stdlib bcmath — not advertised on reference profile (#12131, ext/bcmath/bcmath.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('bcmath'), "\n";
echo 'funcs=', (int) function_exists('bcadd'), "\n";
--EXPECT--
loaded=0
funcs=0
