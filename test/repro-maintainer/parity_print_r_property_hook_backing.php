<?php
declare(strict_types=1);

// Issue #8854 — print_r()/var_dump() must hide separate hook backing fields (zend_property_hooks.c)

class Hooked {
    public string $x {
        get => $this->backing;
        set => $this->backing = $value;
    }
    private string $backing = 'secret';
}

ob_start();
print_r(new Hooked());
$out = ob_get_clean();
echo str_contains($out, 'backing') ? "LEAK\n" : "OK\n";
