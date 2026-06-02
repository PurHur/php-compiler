--TEST--
Language: class constants with object expressions — AOT (#4021)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
echo (C::X instanceof stdClass) ? "1\n" : "0\n";
echo (C::X === C::X) ? "1\n" : "0\n";
--EXPECT--
1
1
