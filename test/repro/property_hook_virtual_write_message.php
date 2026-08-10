<?php
/**
 * #26006 / #29674 — get-only virtual write Error text; backed get-only default write
 * (php-src-strict / PROFILE=8.4).
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
$e->name = "no";
echo "backed:       ", $e->name, "\n";
