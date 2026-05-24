--TEST--
Intersection parameter type (A&B) accepts object implementing all interfaces (#1357)
--FILE--
<?php
interface CountableLike {
    public function count(): int;
}
interface StringableLike {
    public function __toString(): string;
}
class Box implements CountableLike, StringableLike {
    public function count(): int { return 2; }
    public function __toString(): string { return 'box'; }
}
function describe(CountableLike&StringableLike $x): string {
    return $x . ':' . $x->count();
}
echo describe(new Box());
?>
--EXPECT--
box:2
