--TEST--
Function-local static array literal (issue #2286, VmString::soundex table)
--FILE--
<?php
function row(): array
{
    static $table = [0, '1', 2];

    return $table;
}
$t = row();
echo $t[0], $t[1], $t[2];
--EXPECT--
012
