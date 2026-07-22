<?php
// Issue #22330 — Closure::fromCallable ReflectionFunction::getName() keeps original name
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
