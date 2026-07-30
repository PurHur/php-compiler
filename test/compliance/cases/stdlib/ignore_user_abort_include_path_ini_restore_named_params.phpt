--TEST--
ignore_user_abort/set_include_path/ini_restore named args + Reflection (VM, issue #23568, basic_functions.stub.php)
--FILE--
<?php
foreach (['ignore_user_abort', 'set_include_path', 'ini_restore'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ' ', implode(',', $names), "\n";
}
echo ignore_user_abort(enable: false), "\n";
echo set_include_path(include_path: get_include_path()), "\n";
ini_restore(option: 'display_errors');
echo "ini_restore ok\n";
--EXPECTF--
ignore_user_abort enable
set_include_path include_path
ini_restore option
0
%a
ini_restore ok
