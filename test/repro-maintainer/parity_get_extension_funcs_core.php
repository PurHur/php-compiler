<?php

declare(strict_types=1);

$funcs = get_extension_funcs('Core');
echo 'is_array=', var_export(is_array($funcs), true), "\n";
echo 'count=', is_array($funcs) ? count($funcs) : -1, "\n";
echo 'has_strlen=', var_export(is_array($funcs) && in_array('strlen', $funcs, true), true), "\n";
echo 'extension_loaded_core=', var_export(extension_loaded('Core'), true), "\n";
echo is_array($funcs) && count($funcs) > 50 && in_array('strlen', $funcs, true) && extension_loaded('Core')
    ? "core_funcs_ok\n"
    : "core_funcs_bad\n";
echo get_extension_funcs('core') !== false && is_array(get_extension_funcs('core')) ? "core_case_insensitive_ok\n" : "core_case_insensitive_bad\n";
echo get_extension_funcs('nonexistent_xyz_11461') === false ? "unknown_ok\n" : "unknown_bad\n";
