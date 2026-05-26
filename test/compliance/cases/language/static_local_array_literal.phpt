--TEST--
Function-local static array literal (VmString::soundex table, issue #2286)
--FILE--
<?php
function f(): string
{
    static $table = [0, '1', '2'];

    return $table[1];
}
echo f(), "\n";
--EXPECT--
1
