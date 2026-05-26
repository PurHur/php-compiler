--TEST--
AOT: class static registry private cache + fromName (issue #2378)
--FILE--
<?php
class Registry {
    private static string $last = '';

    public static function fromName(string $name): string {
        self::$last = $name;
        return self::$last;
    }
}
echo Registry::fromName('ok');
--EXPECT--
ok
