--TEST--
stdlib tempnam() creates a path under the given directory
--FILE--
<?php
$dir = sys_get_temp_dir();
$p = tempnam($dir, 'phpc_tn_');
if (!is_string($p)) {
    echo "fail\n";
} elseif (basename(dirname($p)) === basename($dir) || dirname($p) === $dir) {
    echo "ok\n";
    @unlink($p);
} else {
    echo "badpath\n";
}
--EXPECT--
ok
