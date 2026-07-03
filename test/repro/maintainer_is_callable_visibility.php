<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: is_callable() respects method visibility (#9334).
 *
 * php-src: ext/standard/basic_functions.c — zend_is_callable_at_frame
 */

class IsCallableVisC
{
    private function m(): void
    {
    }

    protected function p(): void
    {
    }

    public function pub(): void
    {
    }

    private static function sm(): void
    {
    }
}

class IsCallableVisD extends IsCallableVisC
{
}

$c = new IsCallableVisC();
echo 'private_instance=', (int) is_callable([$c, 'm']), PHP_EOL;
echo 'protected_instance=', (int) is_callable([$c, 'p']), PHP_EOL;
echo 'public_instance=', (int) is_callable([$c, 'pub']), PHP_EOL;
echo 'private_static=', (int) is_callable([IsCallableVisC::class, 'sm']), PHP_EOL;
echo 'protected_child=', (int) is_callable([new IsCallableVisD(), 'p']), PHP_EOL;
