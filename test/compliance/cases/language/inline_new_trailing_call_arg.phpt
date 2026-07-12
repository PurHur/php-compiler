--TEST--
Language: trailing inline new as non-first call arg (#18191)
--FILE--
<?php
function probe($a, $b = null) {
    echo 'a=', var_export($a, true), ' argc=', func_num_args(), "\n";
}
probe(false, new stdClass());
?>
--EXPECT--
a=false argc=2
