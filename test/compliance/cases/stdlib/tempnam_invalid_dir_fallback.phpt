--TEST--
stdlib tempnam() invalid directory falls back to sys temp dir with Notice (#4401)
--FILE--
<?php
$f = tempnam('/definitely/does/not/exist', 'pfx');
echo realpath(dirname($f)) === realpath(sys_get_temp_dir()) ? "yes\n" : "no\n";
@unlink($f);
--EXPECTF--
PHP Notice:  tempnam(): file created in the system's temporary directory
yes
