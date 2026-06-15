--TEST--
JIT/AOT: typed static array property with empty default (#8716)
--FILE--
<?php
declare(strict_types=1);

final class Registry
{
    private static array $states = [];

    public static function count(): int
    {
        return count(self::$states);
    }
}

var_export(Registry::count());
?>
--EXPECT--
0
