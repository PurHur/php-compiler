--TEST--
Language: readonly class static property compile-time fatal (#6862)
--FILE--
<?php
declare(strict_types=1);

readonly class R {
    public static string $label = 'shared';
}
echo "ok\n";
--EXPECT_EXIT--
255
