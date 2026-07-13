--TEST--
Language: (bool)/(int)/(float) object cast — Zend cast_object (#3451, #18444)
--FILE--
<?php
class B {
    public function __toString(): string { return '42'; }
}
$o = new B();
echo (bool) $o ? "true\n" : "false\n";
echo (int) $o, "\n";
echo (float) $o, "\n";

class EmptyToString {
    public function __toString(): string { return ''; }
}
$empty = new EmptyToString();
echo (bool) $empty ? "true\n" : "false\n";
echo (int) $empty, "\n";
echo (float) $empty, "\n";

class NoMagic {}
$plain = new NoMagic();
echo (bool) $plain ? "true\n" : "false\n";
echo (int) $plain, "\n";
echo (float) $plain, "\n";
?>
--EXPECT--
true
1
1
false
1
1
true
1
1
