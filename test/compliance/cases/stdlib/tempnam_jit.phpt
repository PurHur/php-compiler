--TEST--
stdlib tempnam() JIT
--FILE--
<?php
$p = tempnam(sys_get_temp_dir(), 'phpc_jit_tn_');
if (is_string($p) && strlen($p) > 0) {
    echo "ok\n";
    @unlink($p);
} else {
    echo "fail\n";
}
--EXPECT--
ok
