<?php

class Foo {
    public function __construct(public int $x = 0) {}
}

class Holder {
    public const FOO = new Foo;
}

echo get_class(Holder::FOO), "\n";
echo Holder::FOO->x === 0 ? "1\n" : "0\n";
echo Holder::FOO === Holder::FOO ? "1\n" : "0\n";
