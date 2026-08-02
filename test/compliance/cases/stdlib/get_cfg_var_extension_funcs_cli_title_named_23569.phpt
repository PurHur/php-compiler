--TEST--
stdlib: get_cfg_var/get_extension_funcs/cli_set_process_title Zend stub named params (#23569)
--FILE--
<?php
foreach (['get_cfg_var', 'get_extension_funcs', 'cli_set_process_title'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
try {
    $cfg = get_cfg_var(option: 'cfg_file_path');
    echo (is_string($cfg) || false === $cfg) ? "cfg_named_ok\n" : "cfg_named_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo is_array(get_extension_funcs(extension: 'json')) ? "ext_named_ok\n" : "ext_named_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(@cli_set_process_title(title: 'phpc-probe'));
    echo "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_cfg_var option
get_extension_funcs extension
cli_set_process_title title
cfg_named_ok
ext_named_ok
true
