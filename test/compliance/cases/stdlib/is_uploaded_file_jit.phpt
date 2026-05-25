--TEST--
stdlib is_uploaded_file() JIT path (issue #2204)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
if (false === $tmp) {
    echo "notmp\n";
    exit(1);
}
echo is_uploaded_file($tmp) ? "ok\n" : "fail\n";
$plain = tempnam(sys_get_temp_dir(), 'phpc_plain_');
if (false !== $plain) {
    echo is_uploaded_file($plain) ? "plain\n" : "noplain\n";
    @unlink($plain);
}
@unlink($tmp);
--EXPECT--
ok
noplain
