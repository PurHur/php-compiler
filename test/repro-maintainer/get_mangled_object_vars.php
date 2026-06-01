<?php

class Base {
    private string $secret = 'hidden';
    protected int $n = 1;
}

class Child extends Base {
    public string $visible = 'ok';
}

$o = new Child();
echo implode(',', array_keys(get_object_vars($o))), "\n";
$m = get_mangled_object_vars($o);
echo count($m), "\n";
echo $m['visible'], "\n";
echo $m["\0Base\0secret"], "\n";
echo $m["\0*\0n"], "\n";
