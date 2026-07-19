--TEST--
Language: Attribute ctor bitmask from Attribute:: constants (#6913, #20727)
--FILE--
<?php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Rep {}

$args = (new ReflectionClass(Rep::class))
    ->getAttributes('Attribute')[0]
    ->getArguments();
echo $args[0], "\n";
--EXPECT--
68
