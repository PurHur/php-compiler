<?php
// Repro #23569 — get_cfg_var / get_extension_funcs / cli_set_process_title Zend stub names
foreach (['get_cfg_var', 'get_extension_funcs', 'cli_set_process_title'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
try {
    $cfg = get_cfg_var(option: 'cfg_file_path');
    echo is_string($cfg) || false === $cfg ? "cfg_named_ok\n" : "cfg_named_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo is_array(get_extension_funcs(extension: 'json')) ? "ext_named_ok\n" : "ext_named_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo @cli_set_process_title(title: 'phpc-probe') ? "title_named_ok\n" : "title_named_false\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
