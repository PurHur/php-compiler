--TEST--
Language: constructor promotion property collision — compile-time fatal (#4286)
--FILE--
<?php
class C {
    public int $x = 0;
    public function __construct(public int $x) {}
}
new C(1);
--EXPECT_EXIT--
255
