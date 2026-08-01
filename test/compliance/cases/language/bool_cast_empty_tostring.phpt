--TEST--
Language: (bool) cast of object ignores __toString — empty string still true (#26409, Zend/zend_object_handlers.c)
--FILE--
<?php
class EmptyToString {
    public function __toString() { return ''; }
}
class NonEmptyToString {
    public function __toString() { return 'x'; }
}
class ZeroToString {
    public function __toString() { return '0'; }
}

$e = new EmptyToString;
$n = new NonEmptyToString;
$z = new ZeroToString;
$s = new stdClass;

echo 'cast_empty=', (bool)$e ? '1' : '0', "\n";
echo 'cast_nonempty=', (bool)$n ? '1' : '0', "\n";
echo 'cast_zero_str=', (bool)$z ? '1' : '0', "\n";
echo 'cast_std=', (bool)$s ? '1' : '0', "\n";
echo 'boolval_empty=', boolval($e) ? '1' : '0', "\n";
echo 'if_empty=', $e ? '1' : '0', "\n";
echo 'empty_empty=', empty($e) ? '1' : '0', "\n";
--EXPECT--
cast_empty=1
cast_nonempty=1
cast_zero_str=1
cast_std=1
boolval_empty=1
if_empty=1
empty_empty=0
