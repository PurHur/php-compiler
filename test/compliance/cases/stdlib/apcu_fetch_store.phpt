--TEST--
stdlib apcu_store()/apcu_fetch()/apcu_delete()/apcu_clear_cache() (#6574)
--FILE--
<?php
echo function_exists('apcu_fetch') ? "fn\n" : "no-fn\n";
apcu_clear_cache();
var_export(apcu_store('k', ['a' => 1], 60));
echo "\n";
$success = false;
$got = apcu_fetch('k', $success);
var_export($success);
echo "\n";
var_export($got);
echo "\n";
var_export(apcu_exists('k'));
echo "\n";
var_export(apcu_delete('k'));
echo "\n";
var_export(apcu_fetch('k'));
echo "\n";
apcu_store('x', 'y');
apcu_clear_cache();
var_export(apcu_exists('x'));
echo "\n";
$info = apcu_cache_info(true);
echo isset($info['num_entries']) ? "info\n" : "no-info\n";
?>
--EXPECT--
fn
true
true
array (
  'a' => 1,
)
true
true
false
false
info
