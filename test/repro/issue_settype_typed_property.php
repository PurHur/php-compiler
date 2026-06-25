<?php

declare(strict_types=1);

class T
{
    public int $x = 5;
    public static int $s = 2;
}

$o = new T();

$ok = settype($o->x, 'string');
echo 'inst_string ok='.var_export($ok, true).' val='.var_export($o->x, true).' type='.gettype($o->x)."\n";

try {
    settype($o->x, 'array');
    echo "inst_array ok\n";
} catch (Throwable $e) {
    echo 'inst_array '.get_class($e)."\n";
}

$ok = settype(T::$s, 'string');
echo 'static_string ok='.var_export($ok, true).' val='.var_export(T::$s, true).' type='.gettype(T::$s)."\n";

try {
    settype(T::$s, 'array');
    echo "static_array ok\n";
} catch (Throwable $e) {
    echo 'static_array '.get_class($e)."\n";
}

$ok = settype($o->x, 'bool');
echo 'inst_bool ok='.var_export($ok, true).' val='.var_export($o->x, true).' type='.gettype($o->x)."\n";
