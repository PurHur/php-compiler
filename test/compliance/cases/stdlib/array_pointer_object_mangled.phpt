--TEST--
stdlib key() on object — private/protected property names mangled (#3312, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class ArrayPointerObjectMangled {
    private int $a = 1;
    protected int $b = 2;
    public int $x = 3;
}
$o = new ArrayPointerObjectMangled();
reset($o);
echo 'private=', var_export(key($o), true), "\n";
next($o);
echo 'protected=', var_export(key($o), true), "\n";
next($o);
echo 'public=', var_export(key($o), true), "\n";
--EXPECT--
private='' . "\0" . 'ArrayPointerObjectMangled' . "\0" . 'a'
protected='' . "\0" . '*' . "\0" . 'b'
public='x'
