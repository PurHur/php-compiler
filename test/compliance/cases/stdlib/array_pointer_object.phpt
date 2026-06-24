--TEST--
stdlib reset/current/key/next/prev/end/pos on object properties (#11196)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class ArrayPointerObjectC {
    public int $x = 1;
    public int $y = 2;
}
$o = new ArrayPointerObjectC();
echo 'reset=', var_export(reset($o), true), "\n";
echo 'key=', var_export(key($o), true), "\n";
echo 'next=', var_export(next($o), true), "\n";
echo 'key2=', var_export(key($o), true), "\n";
echo 'end=', var_export(end($o), true), "\n";
echo 'prev=', var_export(prev($o), true), "\n";
echo 'pos=', var_export(pos($o), true), "\n";
--EXPECT--
reset=1
key='x'
next=2
key2='y'
end=2
prev=1
pos=1
