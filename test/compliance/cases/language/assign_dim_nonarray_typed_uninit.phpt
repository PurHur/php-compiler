--TEST--
Language: dim-assign/append on uninitialized non-array typed property TypeError (#31819)
--FILE--
<?php
function show(string $label, callable $fn): void {
    try {
        $fn();
        echo "$label: ok\n";
    } catch (Throwable $e) {
        echo "$label: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

show('uninit_string', function () {
    class S1 { public string $s; }
    $o = new S1;
    $o->s[0] = 'x';
});

show('append_uninit_string', function () {
    class S2 { public string $s; }
    $o = new S2;
    $o->s[] = 'x';
});

show('uninit_int', function () {
    class I1 { public int $x; }
    $o = new I1;
    $o->x[0] = 1;
});

show('uninit_bool', function () {
    class B1 { public bool $b; }
    $o = new B1;
    $o->b[0] = 1;
});

show('uninit_float', function () {
    class F1 { public float $f; }
    $o = new F1;
    $o->f[0] = 1;
});

show('uninit_object', function () {
    class O1 { public object $o; }
    $o = new O1;
    $o->o[0] = 1;
});

show('uninit_array', function () {
    class A1 { public array $a; }
    $o = new A1;
    $o->a[0] = 1;
    echo json_encode($o->a) . ' ';
});
?>
--EXPECT--
uninit_string: TypeError: Cannot auto-initialize an array inside property S1::$s of type string
append_uninit_string: TypeError: Cannot auto-initialize an array inside property S2::$s of type string
uninit_int: TypeError: Cannot auto-initialize an array inside property I1::$x of type int
uninit_bool: TypeError: Cannot auto-initialize an array inside property B1::$b of type bool
uninit_float: TypeError: Cannot auto-initialize an array inside property F1::$f of type float
uninit_object: TypeError: Cannot auto-initialize an array inside property O1::$o of type object
[1] uninit_array: ok
