--TEST--
Language: ReflectionConstant attributes — getAttributes() on class constants (#4136)
--FILE--
<?php
#[\Attribute]
class Flag { public function __construct(public string $name) {} }

class C {
    #[Flag('VERSION')]
    public const VERSION = 1;
}

$rc = new ReflectionClass(C::class);
$const = $rc->getReflectionConstant('VERSION');
echo count($const->getAttributes()), "\n";
echo $const->getAttributes()[0]->getName(), "\n";
$attrs = $const->getAttributes(Flag::class);
echo $attrs[0]->getArguments()[0], "\n";
?>
--EXPECT--
1
Flag
VERSION
