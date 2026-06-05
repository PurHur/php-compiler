<?php
// Issue #6146: exterior assignment on ctor-initialized readonly string property
// must throw catchable Error, not VM fatal "Method call on non-object".
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
