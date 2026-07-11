--TEST--
stdlib is_callable() rejects private/protected methods from global scope (#9334, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

class IsCallableVisC9334
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

class IsCallableVisD9334 extends IsCallableVisC9334
{
}

$c = new IsCallableVisC9334();
echo (int) is_callable([$c, 'm']), "\n";
echo (int) is_callable([$c, 'p']), "\n";
echo (int) is_callable([$c, 'pub']), "\n";
echo (int) is_callable([IsCallableVisC9334::class, 'sm']), "\n";
echo (int) is_callable([new IsCallableVisD9334(), 'p']), "\n";
--EXPECT--
0
0
1
0
0
