--TEST--
AOT: (bool) native object — zend_std_cast_object_to_type(_IS_BOOL) is true (#32463)
--FILE--
<?php
$o = new stdClass();
var_dump((bool) $o);
var_dump((bool) (new stdClass()));
class EmptyToString {
    public function __toString()
    {
        return '';
    }
}
var_dump((bool) (new EmptyToString()));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
