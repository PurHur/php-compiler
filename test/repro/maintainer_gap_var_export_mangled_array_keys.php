<?php

declare(strict_types=1);

class C {
    protected $w = 1;
    private $p = 2;
}

$exported = var_export(get_mangled_object_vars(new C()), true);
if (!str_contains($exported, '\0')) {
    fwrite(STDERR, "missing NUL escape in export\n");
    exit(1);
}
echo "ok\n";
