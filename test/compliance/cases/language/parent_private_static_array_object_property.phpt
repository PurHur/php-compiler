--TEST--
Language: object in parent private static array keeps properties (#31937, zend_object_handlers.c)
--FILE--
<?php
abstract class Registry
{
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
echo Child::getInstance()->name, "\n";
--EXPECT--
singleton
singleton
