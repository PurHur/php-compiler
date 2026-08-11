--TEST--
DateTime subclass inherits DateTimeInterface format constants without ambiguity (#30229)
--FILE--
<?php
class MyDate extends DateTime
{
}
$r = new ReflectionClassConstant(DateTime::class, 'ATOM');
echo $r->getDeclaringClass()->getName(), "\n";
$rm = new ReflectionClassConstant(MyDate::class, 'ATOM');
echo $rm->getDeclaringClass()->getName(), "\n";
$d = DateTime::createFromInterface(new MyDate('2020-01-01'));
echo $d->format('Y-m-d'), "\n";
--EXPECT--
DateTimeInterface
DateTimeInterface
2020-01-01
