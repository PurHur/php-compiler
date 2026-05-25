--TEST--
AOT: move_uploaded_file() via __compiler_move_uploaded_file (issue #2005)
--FILE--
<?php
$tmpdir = sys_get_temp_dir();
$from = tempnam($tmpdir, 'phpc_upload_');
$dest = tempnam($tmpdir, 'phpc_aot_move_');
@unlink($dest);
if (move_uploaded_file($from, $dest)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (is_file($dest)) {
    echo 'moved', "\n";
} else {
    echo 'nomoved', "\n";
}
@unlink($dest);
--EXPECT--
ok
moved
