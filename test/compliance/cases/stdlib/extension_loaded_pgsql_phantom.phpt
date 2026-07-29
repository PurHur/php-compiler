--TEST--
stdlib extension_loaded('pgsql') withheld without host ext/pgsql (#24994, #24627)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('pgsql'), "\n";
echo 'in_list=', (int) in_array('pgsql', get_loaded_extensions(), true), "\n";
echo 'pg_connect=', (int) function_exists('pg_connect'), "\n";
echo 'force_new=', (int) defined('PGSQL_CONNECT_FORCE_NEW'), "\n";
echo 'ctx_vis=', (int) function_exists('pg_set_error_context_visibility'), "\n";
$c = get_defined_constants(true);
echo 'bucket=', (int) isset($c['pgsql']), "\n";
--EXPECT--
loaded=0
in_list=0
pg_connect=0
force_new=0
ctx_vis=0
bucket=0
