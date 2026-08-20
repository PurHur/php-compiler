--TEST--
AOT: class static property string concat persists (#32899)
--FILE--
<?php
class C
{
    public static $s = 'hi';
}

function f(): string
{
    C::$s .= '!';

    return C::$s;
}

echo f(), f(), "\n";

C::$s = 'hi';
C::$s = C::$s.'!';
echo C::$s, "\n";
--EXPECT--
hi!hi!!
hi!
--EXPECT_EXIT--
0
