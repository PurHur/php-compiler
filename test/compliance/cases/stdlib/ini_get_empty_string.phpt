--TEST--
Stdlib: ini_get() unset string directives return empty string (#12178, ext/standard/ini.c)
--FILE--
<?php
foreach (['auto_prepend_file', 'auto_append_file', 'error_log', 'doc_root', 'user_dir'] as $key) {
    $v = ini_get($key);
    echo $key.':'.(gettype($v) === 'string' && $v === '' ? 'empty' : 'bad')."\n";
}
echo ini_get('bogus_xyz_123') === false ? "bogus-false\n" : "bogus-bad\n";
--EXPECT--
auto_prepend_file:empty
auto_append_file:empty
error_log:empty
doc_root:empty
user_dir:empty
bogus-false
