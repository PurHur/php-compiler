--TEST--
Stdlib: ini_get() unset string directives return empty string (#12178, #12915, ext/standard/ini.c)
--FILE--
<?php
foreach ([
    'auto_prepend_file', 'auto_append_file', 'error_log', 'doc_root', 'user_dir',
    'disable_functions', 'disable_classes', 'open_basedir', 'mail.add_x_header',
    'error_append_string', 'error_prepend_string',
] as $key) {
    $v = ini_get($key);
    echo $key.':'.(gettype($v) === 'string' && $v === '' ? 'empty' : 'bad')."\n";
}
echo ini_get('user_ini.filename') === '.user.ini' ? "user-ini-default\n" : "user-ini-bad\n";
echo ini_get('bogus_xyz_123') === false ? "bogus-false\n" : "bogus-bad\n";
--EXPECT--
auto_prepend_file:empty
auto_append_file:empty
error_log:empty
doc_root:empty
user_dir:empty
disable_functions:empty
disable_classes:empty
open_basedir:empty
mail.add_x_header:empty
error_append_string:empty
error_prepend_string:empty
user-ini-default
bogus-false
