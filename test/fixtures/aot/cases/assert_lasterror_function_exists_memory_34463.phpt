--TEST--
AOT: assert/error_get_last/function_exists/memory after Type::initialize lazy-link (#34463)
--FILE--
<?php
echo function_exists('strlen') ? "fe_ok\n" : "fe_no\n";
@trigger_error('x', E_USER_NOTICE);
$e = error_get_last();
echo is_array($e) && isset($e['message']) ? "el_ok\n" : "el_no\n";
assert(true);
echo "as_ok\n";
echo assert_options(ASSERT_ACTIVE) !== false ? "ao_ok\n" : "ao_no\n";
echo memory_get_usage() > 0 ? "mu_ok\n" : "mu_no\n";
--EXPECT--
fe_ok
el_ok
as_ok
ao_ok
mu_ok
