<?php
class C {
    private int $hidden = 99;
    public function __debugInfo(): array {
        return ['k' => 1];
    }
}
ob_start();
var_dump(new C());
$vd = ob_get_clean();
echo str_contains($vd, 'k') ? "var_dump_ok\n" : "var_dump_fail\n";

ob_start();
print_r(new C());
$pr = ob_get_clean();
echo str_contains($pr, 'k') ? "print_r_ok\n" : "print_r_fail\n";
