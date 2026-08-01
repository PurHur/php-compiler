--TEST--
Language: concrete promoted constructor still accepted (#26529)
--FILE--
<?php
class C {
    public function __construct(public int $x) {}
}
echo (new C(7))->x, "\n";
--EXPECT--
7
