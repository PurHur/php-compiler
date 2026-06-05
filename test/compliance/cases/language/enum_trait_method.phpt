--TEST--
Language: enum trait instance methods — case->method() must dispatch trait methods (#5709)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public function tag(): string { return 't'; }
}

enum E: int {
    case A = 1;
    use T;
}

echo E::A->tag(), "\n";

trait UTrait {
    public function mark(): string { return 'u'; }
}

enum U {
    case X;
    use UTrait;
}

echo U::X->mark(), "\n";
--EXPECT--
t
u
