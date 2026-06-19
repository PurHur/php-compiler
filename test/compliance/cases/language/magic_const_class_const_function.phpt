--TEST--
Language: __FUNCTION__ in class const resolves to empty string (#10125)
--FILE--
<?php
class C {
    public const X = __CLASS__ . '::' . __FUNCTION__;
}
echo C::X, "\n";
?>
--EXPECT--
C::
