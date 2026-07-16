--TEST--
stdlib bcmath — not advertised on reference profile (#12131, #19608)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('bcmath'), "\n";
echo 'funcs=', (int) function_exists('bcadd'), "\n";
echo 'Number=', (int) class_exists('BcMath\\Number', false), "\n";
--EXPECT--
loaded=0
funcs=0
Number=0
