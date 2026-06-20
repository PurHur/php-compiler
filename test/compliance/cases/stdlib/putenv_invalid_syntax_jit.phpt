--TEST--
stdlib putenv() invalid assignment syntax throws ValueError on JIT (#10335)
--FILE--
<?php
declare(strict_types=1);

try {
    putenv('=invalid');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
putenv(): Argument #1 ($assignment) must have a valid syntax
