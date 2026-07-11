--TEST--
stdlib sprintf() Stringable object — __toString coercion (#13467)
--FILE--
<?php
class C implements Stringable {
    public function __toString(): string {
        return 'obj';
    }
}
echo sprintf('%s', new C()), "\n";
--EXPECT--
obj
