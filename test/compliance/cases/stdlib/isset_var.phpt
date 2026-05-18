--TEST--
stdlib isset() on a defined scalar variable
--FILE--
<?php
$name = 'hi';
if (isset($name)) {
    echo "set\n";
}
--EXPECT--
set
