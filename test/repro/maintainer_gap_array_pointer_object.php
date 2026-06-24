<?php

declare(strict_types=1);

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
echo 'current=', var_export(current($o), true), "\n";
