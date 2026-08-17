<?php
// Dim-assign / append on uninitialized non-array typed properties
error_reporting(E_ALL);

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

// Control: array typed still auto-inits (#31770)
show('uninit_array', function () {
    class A1 { public array $a; }
    $o = new A1;
    $o->a[0] = 1;
    echo json_encode($o->a) . ' ';
});
