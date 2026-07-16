--TEST--
stdlib bcmath — Number/bcadd/extension_loaded paired on PROFILE=8.4 (#19608)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('bcmath'), "\n";
echo 'funcs=', (int) function_exists('bcadd'), "\n";
echo 'Number=', (int) class_exists('BcMath\\Number', false), "\n";
if (class_exists('BcMath\\Number', false)) {
    $n = new \BcMath\Number('10');
    echo 'add=', (string) $n->add('2'), "\n";
}
--EXPECT--
loaded=1
funcs=1
Number=1
add=12
