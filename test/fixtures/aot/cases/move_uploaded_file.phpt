--TEST--
AOT: move_uploaded_file() persists multipart temp (issue #2005)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
file_put_contents($tmp, 'aot-payload');
$dest = tempnam(sys_get_temp_dir(), 'phpc_saved_');
@unlink($dest);
if (move_uploaded_file($tmp, $dest)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
if (is_file($tmp)) {
    echo "src\n";
} else {
    echo "gone\n";
}
@unlink($dest);
--EXPECT--
ok
gone
