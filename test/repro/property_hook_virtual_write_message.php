<?php
/**
 * #26006 — get-only virtual property write Error text (php-src-strict / PROFILE=8.4).
 *
 * php-src PHP-8.4 Zend/zend_object_handlers.c (hooked write, no set, ZEND_ACC_VIRTUAL):
 *   "Property %s::$%s is read-only"
 *
 * "Must not write to virtual property" is only for raw backing-slot access inside a hook
 * (zend_throw_no_prop_backing_value_access) — not the external get-only write path.
 *
 * php-src master tip uses "Cannot write to get-only virtual property …" for the external
 * path; keep PROFILE=8.4 on the PHP-8.4 string until a forward profile opts in.
 */
class C {
    public string $full {
        get => "x";
    }
    public int $n {
        get => 1;
    }
}
$c = new C();
try {
    $c->full = "y";
} catch (Error $e) {
    echo "arrow assign: ", $e->getMessage(), "\n";
}
try {
    $c->n++;
} catch (Error $e) {
    echo "arrow ++:     ", $e->getMessage(), "\n";
}

class E {
    public string $name = "ok" {
        get => $this->name;
    }
}
$e = new E();
try {
    $e->name = "no";
} catch (Error $e2) {
    echo "backed:       ", $e2->getMessage(), "\n";
}
