<?php
// Repro #25748 — __debugInfo throw → Zend Warning+Fatal, not catchable
class C {
    public function __debugInfo() { throw new RuntimeException('x'); }
}
try {
    var_dump(new C());
    echo "var_dump_returned\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
