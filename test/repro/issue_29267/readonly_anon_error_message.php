<?php
// #29267 — readonly anon property Error must use Zend display name (no NUL/path).
// Use promoted ctor init: Zend fatals on `public string $a = "x"` inside readonly class.
$o = new readonly class {
    public function __construct(public string $a = "x") {}
};
try {
    $o->a = "y";
    echo "UNEXPECTED_OK\n";
} catch (Error $e) {
    $msg = $e->getMessage();
    echo "msg=" . $msg . "\n";
    echo "has_nul=" . (strpos($msg, "\0") !== false ? "yes" : "no") . "\n";
    echo "exact=" . ($msg === 'Cannot modify readonly property class@anonymous::$a' ? "yes" : "no") . "\n";
}
