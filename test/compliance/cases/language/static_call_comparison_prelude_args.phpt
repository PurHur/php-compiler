--TEST--
language static call — multiple hoisted !== comparison preludes wire per-arg (#17259)
--FILE--
<?php

declare(strict_types=1);

final class ComparePreludeHelper
{
    public static function extendedArgv(
        string $str,
        string $mask,
        int $offset,
        int $length,
        bool $lenIsNull,
        bool $isStrspn
    ): int {
        return $isStrspn ? ($lenIsNull ? 10 : 20) : 0;
    }

    public static function extendedArgvInt(
        string $str,
        string $mask,
        int $offset,
        int $length,
        int $lenIsNull,
        int $isStrspn
    ): int {
        return self::extendedArgv(
            $str,
            $mask,
            $offset,
            $length,
            0 !== $lenIsNull,
            0 !== $isStrspn
        );
    }
}

echo ComparePreludeHelper::extendedArgvInt('a', 'b', 0, 1, 0, 1), "\n";
echo ComparePreludeHelper::extendedArgvInt('a', 'b', 0, 1, 1, 1), "\n";
echo "ok\n";
?>
--EXPECT--
20
10
ok
