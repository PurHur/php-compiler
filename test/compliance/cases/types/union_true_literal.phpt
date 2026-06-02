--TEST--
Union with standalone true type accepts int and boolean true (PHP 8.2+, issue #4132)
--FILE--
<?php
function f(bool $flag): int|true
{
    return $flag ? 42 : true;
}
echo f(true), "\n";
echo f(false) === true ? 'true' : 'false', "\n";
?>
--EXPECT--
42
true
