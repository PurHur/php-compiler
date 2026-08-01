--TEST--
Language: __set_state(): object/self/class compiles (#26484)
--FILE--
<?php
class C {
    public static function __set_state(array $a): object {
        return new self;
    }
}
class D {
    public static function __set_state(array $a): self {
        return new self;
    }
}
class E {
    public static function __set_state(array $a): E {
        return new self;
    }
}
$c = C::__set_state([]);
$d = D::__set_state([]);
$e = E::__set_state([]);
echo get_class($c), PHP_EOL;
echo get_class($d), PHP_EOL;
echo get_class($e), PHP_EOL;
--EXPECT--
C
D
E
