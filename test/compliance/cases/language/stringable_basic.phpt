--TEST--
Stringable interface exists and __toString cast (issue #3296)
--FILE--
<?php
echo interface_exists('Stringable') ? '1' : '0', "\n";

class Ok implements Stringable {
    public function __toString(): string {
        return 'ok';
    }
}

class HasToString {
    public function __toString(): string {
        return 'legacy';
    }
}

echo (string) new Ok(), "\n";
echo new Ok(), "\n";
echo (string) new HasToString(), "\n";
echo interface_exists(Stringable::class) ? '1' : '0', "\n";
--EXPECT--
1
ok
ok
legacy
1
