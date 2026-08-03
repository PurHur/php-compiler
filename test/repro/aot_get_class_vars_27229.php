<?php
/**
 * Repro #27229 — AOT get_class_vars() must match Zend/VM/JIT (public instance + static; omit private).
 *
 * Avoid count()/array_keys()/array_values() — those abort on thin AOT hashtables generally (#27211).
 *
 * Run: ./script/docker-exec.sh -- bash -lc './phpc build -o /tmp/gcv.bin test/repro/aot_get_class_vars_27229.php && /tmp/gcv.bin'
 */
class C
{
    public $a = 1;
    private $b = 2;
    public static $c = 3;
}
$r = get_class_vars('C');
if (!is_array($r)) {
    echo 'NOTARRAY:'.gettype($r), "\n";
    exit(1);
}
$keys = [];
$vals = [];
foreach ($r as $k => $v) {
    $keys[] = $k;
    $vals[] = $v;
}
echo implode(',', $keys), '|', implode(',', $vals), "\n";
echo array_key_exists('b', $r) ? "b-yes\n" : "b-no\n";
