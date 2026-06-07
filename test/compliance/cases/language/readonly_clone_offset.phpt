--TEST--
language: readonly class clone rejects nested array offset writes (issue #7245)
--FILE--
<?php
readonly class C {
    public function __construct(public array $a) {}
}
$c = new C([1]);
$d = clone $c;
try {
    $d->a[0] = 2;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $c->a[0], ',', $d->a[0], "\n";
--EXPECT--
Error: Cannot modify readonly property C::$a
1,1
