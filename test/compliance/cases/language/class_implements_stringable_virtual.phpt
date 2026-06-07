--TEST--
class_implements() and is_a() include virtual Stringable (issue #7203)
--FILE--
<?php
class S {
    public function __toString(): string {
        return 'x';
    }
}

class NotStringable {}

$s = new S();
echo in_array('Stringable', class_implements($s), true) ? "implements_yes\n" : "implements_no\n";
echo is_a($s, Stringable::class, true) ? "is_a_yes\n" : "is_a_no\n";
echo in_array('Stringable', class_implements('S'), true) ? "class_yes\n" : "class_no\n";
echo in_array('Stringable', class_implements(new NotStringable()), true) ? "bad_yes\n" : "bad_no\n";
--EXPECT--
implements_yes
is_a_yes
class_yes
bad_no
