<?php

declare(strict_types=1);

/**
 * AOT: forward_static_call(['Class','method']) (#35110).
 *
 * php-src: ext/standard/basic_functions.c PHP_FUNCTION(forward_static_call)
 */

class A35110
{
    public static function f(): int
    {
        return 1;
    }
}

class B35110 extends A35110
{
    public static function f(): int
    {
        return forward_static_call(['A35110', 'f']);
    }

    public static function g(): int
    {
        return forward_static_call('A35110::f');
    }
}

echo B35110::f();
echo '|';
echo B35110::g();
echo "\n";
