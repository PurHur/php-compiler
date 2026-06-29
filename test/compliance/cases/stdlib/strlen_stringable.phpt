--TEST--
stdlib strlen() Stringable object — __toString byte length (#13468)
--FILE--
<?php
class C implements Stringable {
    public function __toString(): string {
        return 'obj';
    }
}
echo strlen(new C()), "\n";
--EXPECT--
3
