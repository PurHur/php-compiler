--TEST--
language Closure getCurrent/fromStatic/getUsedVariables withheld on PROFILE=8.4 (#22583, Zend/zend_closures.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['getCurrent', 'fromStatic', 'getUsedVariables'] as $m) {
    echo $m, '=', method_exists(Closure::class, $m) ? '1' : '0', "\n";
}
?>
--EXPECT--
getCurrent=0
fromStatic=0
getUsedVariables=0
