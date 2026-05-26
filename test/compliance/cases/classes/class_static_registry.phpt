--TEST--
Class static registry: private static property + static method (issue #2378)
--FILE--
<?php
class Registry {
    /** @var array<string, string> */
    private static array $cache = [];

    public static function fromName(string $name): string {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = $name;
        }
        return self::$cache[$name];
    }
}
echo Registry::fromName('alpha');
echo "\n";
echo Registry::fromName('alpha');
--EXPECT--
alpha
alpha
