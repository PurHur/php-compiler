--TEST--
stdlib extension_loaded('pgsql') withheld under PROFILE=8.4 without host (#24994, #24627)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('pgsql'), "\n";
echo 'pg_connect=', (int) function_exists('pg_connect'), "\n";
echo 'ctx_vis=', (int) function_exists('pg_set_error_context_visibility'), "\n";
$c = get_defined_constants(true);
echo 'bucket=', (int) isset($c['pgsql']), "\n";
--EXPECT--
loaded=0
pg_connect=0
ctx_vis=0
bucket=0
