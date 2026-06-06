--TEST--
Language: enum case dynamic method call — trait-merged methods via variable name (#6638)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public function m(): string { return 't'; }
}

enum E: string {
    case A = 'a';
    use T;
}

$method = 'm';
echo E::A->$method(), "\n";

trait UTrait {
    public function mark(): string { return 'u'; }
}

enum U {
    case X;
    use UTrait;
}

$name = 'mark';
echo U::X->$name(), "\n";
--EXPECT--
t
u
