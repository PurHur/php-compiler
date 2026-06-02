--TEST--
Language: constructor promotion duplicate parameter name — compile-time fatal (#4282)
--FILE--
<?php
class C {
    public function __construct(public int $x, int $x) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
