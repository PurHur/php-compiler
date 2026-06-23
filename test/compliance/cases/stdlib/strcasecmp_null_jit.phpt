--TEST--
stdlib strcasecmp() JIT — null operand TypeError (#10990)
--JIT--
--FILE--
<?php
foreach ([['a', null, 'string2'], [null, 'a', 'string1']] as [$a, $b, $which]) {
    try {
        strcasecmp($a, $b);
        echo "uncaught $which\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
%A
strcasecmp(): Argument #2 ($string2) must be of type string, null given
strcasecmp(): Argument #1 ($string1) must be of type string, null given
