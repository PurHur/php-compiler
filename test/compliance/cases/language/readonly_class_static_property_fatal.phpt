--TEST--
Language: readonly class static property with default — cannot have default value (#6862, #29980)
--FILE--
<?php
declare(strict_types=1);

readonly class R {
    public static string $label = 'shared';
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Readonly property R::$label cannot have default value
