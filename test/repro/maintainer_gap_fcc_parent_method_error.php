<?php

declare(strict_types=1);

/**
 * Issue #14928: parenthesized inherited instance-method FCC must Error with Zend wording.
 *
 * Zend: Undefined constant Child::parentMethod
 * (not Undefined class constant)
 */

class Parent_
{
    public function parentMethod(): int
    {
        return 1;
    }
}

class Child extends Parent_
{
}

try {
    (Child::parentMethod)(...);
    echo "no error\n";
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'Undefined constant')) {
        echo 'wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo $e->getMessage(), "\n";
}

try {
    Child::parentMethod(...);
    echo "unexpected success\n";
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
