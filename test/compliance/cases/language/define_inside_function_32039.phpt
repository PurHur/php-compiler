--TEST--
Language: define('LIT') inside function silent on first call, warns on second (#32039, zend_builtin_functions.c)
--FILE--
<?php
error_reporting(E_ALL);
echo 'before=', var_export(defined('PROBE_C_ISOLATED'), true), "\n";
function probe_define(): void
{
    define('PROBE_C_ISOLATED', 1);
    echo 'defined_ci=', var_export(defined('probe_c_isolated'), true), "\n";
    echo 'defined_CS=', var_export(defined('PROBE_C_ISOLATED'), true), "\n";
}
probe_define();
echo 'after=', var_export(defined('PROBE_C_ISOLATED'), true), "\n";
echo 'val=', var_export(constant('PROBE_C_ISOLATED'), true), "\n";
probe_define();
define('FILE_SCOPE_D32039', 2);
echo 'file=', var_export(defined('FILE_SCOPE_D32039'), true), "\n";
--EXPECTF--
PHP Warning:  Constant PROBE_C_ISOLATED already defined in %s on line %d
before=false
defined_ci=false
defined_CS=true
after=true
val=1
defined_ci=false
defined_CS=true
file=true
