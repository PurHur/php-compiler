<?php
// Issue #23568 — ignore_user_abort / set_include_path / ini_restore Zend stub names.
foreach (['ignore_user_abort', 'set_include_path', 'ini_restore'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
var_export(ignore_user_abort(enable: false));
echo "\n";
var_export(set_include_path(include_path: get_include_path()));
echo "\n";
ini_restore(option: 'display_errors');
echo "ini_restore ok\n";
