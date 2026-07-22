--TEST--
language: Closure::fromCallable ReflectionFunction::getName() keeps original (#22330, zend_closures.c)
--FILE--
<?php
$f = Closure::fromCallable('strlen');
echo (new ReflectionFunction($f))->getName(), "\n";
echo (new ReflectionFunction($f))->isClosure() ? "isClosure=1\n" : "isClosure=0\n";
$g = Closure::fromCallable(['DateTime', 'createFromFormat']);
echo (new ReflectionFunction($g))->getName(), "\n";
class T
{
    public function f()
    {
        return 1;
    }
}
$h = Closure::fromCallable([new T(), 'f']);
echo (new ReflectionFunction($h))->getName(), "\n";
echo $f('ab'), "\n";
?>
--EXPECT--
strlen
isClosure=1
createFromFormat
f
2
