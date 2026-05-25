--TEST--
stdlib move_uploaded_file() JIT path (issue #2005, #1492)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/move_uploaded_file_fixture';
@mkdir($base, 0777, true);
$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
file_put_contents($tmp, 'jit-upload');
$dest = $base . '/jit_saved.dat';
if (move_uploaded_file($tmp, $dest)) {
    echo file_get_contents($dest), "\n";
    unlink($dest);
} else {
    echo "fail\n";
}
--EXPECT--
jit-upload
