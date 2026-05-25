--TEST--
AOT: class member constants private/public (#2199)
--FILE--
<?php
class Limits
{
    private const PRIVATE_MAX = 200;

    public function max(): int
    {
        return self::PRIVATE_MAX;
    }
}

echo (new Limits())->max();
echo "\n";
--EXPECT--
200
