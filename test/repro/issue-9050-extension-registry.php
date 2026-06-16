<?php
foreach (['standard', 'spl', 'openssl', 'json', 'date', 'pcre', 'zlib'] as $name) {
    echo $name, ' loaded=', var_export(extension_loaded($name), true), "\n";
    $funcs = get_extension_funcs($name);
    echo $name, ' funcs=', is_array($funcs) ? count($funcs) : var_export($funcs, true), "\n";
    echo $name, ' version=', var_export(phpversion($name), true), "\n";
}
