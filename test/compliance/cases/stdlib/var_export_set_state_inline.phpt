--TEST--
stdlib var_export(VE::__set_state([]), true) exports __set_state snippet (#11896, ext/standard/var.c)
--FILE--
<?php
class VE {
    public static function __set_state(array $a): self { return new self(); }
}
echo var_export(VE::__set_state([]), true);
--EXPECTREGEX--
VE::__set_state\(array
--EXPECT_EXIT--
0
