--TEST--
Language: parenthesized enum case (E::A)() __invoke call (#7386, zend_compile.c)
--FILE--
<?php
enum E {
    case A;
    public function __invoke(): int { return 1; }
}
var_dump((E::A)());
echo (E::A)(), "\n";
$x = (E::A)();
var_dump($x);

enum E2: string {
    case A = 'x';
    public function __invoke(): string { return 'y'; }
}
var_dump((E2::A)());
echo (E2::A)(), "\n";
--EXPECT--
int(1)
1
int(1)
string(1) "y"
y
