--TEST--
stdlib ReflectionMethod::invoke() and invokeArgs()
--FILE--
<?php
declare(strict_types=1);

class Greeter {
    public function hello(string $name): string {
        return "hi {$name}";
    }
    public static function stat(): string {
        return 'static';
    }
}

$rm = new ReflectionMethod(Greeter::class, 'hello');
echo $rm->invoke(new Greeter(), 'world'), "\n";

$rs = new ReflectionMethod(Greeter::class, 'stat');
echo $rs->invoke(null), "\n";

$rmArgs = new ReflectionMethod(Greeter::class, 'hello');
echo $rmArgs->invokeArgs(new Greeter(), ['world']), "\n";

$d = new DateTime('2020-01-15');
$internal = new ReflectionMethod(DateTime::class, 'format');
echo $internal->invoke($d, 'Y'), "\n";
echo $internal->invokeArgs($d, ['Y']), "\n";
--EXPECT--
hi world
static
hi world
2020
2020
