--TEST--
readonly class: promoted string ctor-init exterior write rejected JIT (issue #6146)
--FILE--
<?php
readonly class RC {
    public function __construct(public string $x = 'init') {}
}
$r = new RC();
try {
    $r->x = 'nope';
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property RC::$x
