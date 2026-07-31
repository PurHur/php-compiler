--TEST--
Stringable: untyped public __toString is LSP-compatible with : string (issue #25727)
--FILE--
<?php
class S implements Stringable {
    public function __toString() {
        return 'hi';
    }
}

class ParentTyped {
    public function __toString(): string {
        return 'p';
    }
}

class ChildUntyped extends ParentTyped {
    public function __toString() {
        return 'c';
    }
}

echo (string) (new S()), "\n";
echo (string) (new ChildUntyped()), "\n";
--EXPECT--
hi
c
