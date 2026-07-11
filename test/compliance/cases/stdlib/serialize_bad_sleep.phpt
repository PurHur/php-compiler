--TEST--
stdlib serialize() __sleep() non-array — Warning + N; (#13378, ext/standard/var.c)
--FILE--
<?php
class BadSleep {
    public function __sleep() { return 1; }
}
echo serialize(new BadSleep()), "\n";
?>
--EXPECTF--
PHP Warning:  serialize(): BadSleep::__sleep() should return an array only containing the names of instance-variables to serialize in %s on line %d
N;
