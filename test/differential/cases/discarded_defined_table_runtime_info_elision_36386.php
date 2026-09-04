<?php

declare(strict_types=1);

/**
 * Discarded get_loaded_extensions / get_defined_constants / get_defined_functions
 * must not change observable live results (#36386).
 *
 * php-src: ext/standard/basic_functions.c, ext/standard/info.c
 */

get_loaded_extensions();
get_loaded_extensions(false);
get_defined_constants();
get_defined_constants(false);
get_defined_functions();
get_defined_functions(false);

$ext = get_loaded_extensions();
$consts = get_defined_constants();
$fns = get_defined_functions();

// is_array only for extensions — AOT helper may return an empty table.
echo (is_array($ext) ? '1' : '0')
    . (is_array($consts) && count($consts) > 0 ? '1' : '0')
    . (is_array($fns) && isset($fns['internal']) ? '1' : '0'), "\n";
