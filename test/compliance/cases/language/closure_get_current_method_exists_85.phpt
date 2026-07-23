--TEST--
language Closure::getCurrent method_exists on PROFILE=8.5; fromStatic/getUsedVariables still withheld (#22583)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

foreach (['getCurrent', 'fromStatic', 'getUsedVariables'] as $m) {
    echo $m, '=', method_exists(Closure::class, $m) ? '1' : '0', "\n";
}
?>
--EXPECT--
getCurrent=1
fromStatic=0
getUsedVariables=0
