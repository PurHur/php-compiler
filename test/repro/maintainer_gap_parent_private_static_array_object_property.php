<?php
/**
 * #31937 — object stored in a parent private static array must keep property values.
 *
 * Zend: echo $inst->name prints "singleton".
 */
abstract class Registry
{
    /** @var array<string, static> */
    private static array $instances = [];

    public static function getInstance(): static
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            $obj = new static();
            $obj->name = 'singleton';
            self::$instances[$class] = $obj;
        }

        return self::$instances[$class];
    }
}

class Child extends Registry
{
    public $name;
}

$inst = Child::getInstance();
echo $inst->name, "\n";
