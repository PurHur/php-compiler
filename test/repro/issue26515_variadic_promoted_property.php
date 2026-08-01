<?php
// Issue #26515 — Zend rejects variadic constructor property promotion.
class C {
    public function __construct(public int ...$x) {}
}
echo "ok\n";
