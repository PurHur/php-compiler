<?php

/**
 * Repro #26370 — #[\Deprecated] on property hooks must emit E_USER_DEPRECATED
 * (Zend Method Class::$prop::get/set()).
 */
set_error_handler(function (int $n, string $s): bool {
    echo 'DEP:', $s, "\n";

    return true;
});

class C {
    public string $x {
        #[\Deprecated]
        get => 'a';
        set {}
    }
}

$c = new C;
echo $c->x, "\n";
