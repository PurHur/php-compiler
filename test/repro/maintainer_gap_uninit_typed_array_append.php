<?php
/**
 * Dim-assign / append on uninitialized typed array properties.
 * Zend auto-inits [] then writes; VM throws typed-property Error.
 */
error_reporting(E_ALL);

class C
{
    public array $a;
    public string $s;
    public ?array $n;
}

$o = new C();
try {
    $o->a[0] = 1;
    echo "idx=" . json_encode($o->a) . "\n";
} catch (Throwable $e) {
    echo "idx=" . get_class($e) . ":" . $e->getMessage() . "\n";
}

try {
    $o->s .= "x";
    echo "dot={$o->s}\n";
} catch (Throwable $e) {
    echo "dot=" . get_class($e) . ":" . $e->getMessage() . "\n";
}

$o2 = new C();
try {
    $o2->n[] = 2;
    echo "npush=" . json_encode($o2->n) . "\n";
} catch (Throwable $e) {
    echo "npush=" . get_class($e) . ":" . $e->getMessage() . "\n";
}

$o3 = new C();
try {
    $o3->a[] = 3;
    echo "append=" . json_encode($o3->a) . "\n";
} catch (Throwable $e) {
    echo "append=" . get_class($e) . ":" . $e->getMessage() . "\n";
}
