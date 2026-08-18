<?php
error_reporting(E_ALL);
function probe_define(): void
{
    define('PROBE_C_ISOLATED', 1);
    echo 'defined_ci=', var_export(defined('probe_c_isolated'), true), "\n";
    echo 'defined_CS=', var_export(defined('PROBE_C_ISOLATED'), true), "\n";
}
probe_define();
