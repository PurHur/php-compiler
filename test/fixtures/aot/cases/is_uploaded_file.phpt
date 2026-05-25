--TEST--
AOT: is_uploaded_file() via __compiler_is_uploaded_file (issue #2204)
--FILE--
<?php
$tmpdir = sys_get_temp_dir();
$tmp = tempnam($tmpdir, 'phpc_upload_');
echo is_uploaded_file($tmp) ? "yes\n" : "no\n";
$plain = tempnam($tmpdir, 'phpc_plain_');
echo is_uploaded_file($plain) ? "plain\n" : "noplain\n";
@unlink($tmp);
@unlink($plain);
--EXPECT--
yes
noplain
