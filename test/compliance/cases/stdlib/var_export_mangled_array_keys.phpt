--TEST--
stdlib var_export() mangled array keys with NUL bytes (#13875, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
class C13875 {
    protected $w = 1;
    private $p = 2;
}
$exported = var_export(get_mangled_object_vars(new C13875()), true);
echo str_contains($exported, '\0') ? "ok\n" : "fail\n";
--EXPECT--
ok
