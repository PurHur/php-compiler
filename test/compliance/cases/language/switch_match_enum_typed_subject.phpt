--TEST--
Language: switch/match on enum-typed subject compare enum objects (#8767, zend_execute.c)
--FILE--
<?php
enum E: string { case A = "a"; case B = "b"; }
function f(E $e): void {
    switch ($e) {
        case E::A: echo "A\n"; return;
        case E::B: echo "B\n"; return;
    }
    echo "none\n";
}
f(E::A);
echo match (E::B) {
    E::A => "no",
    E::B => "B",
}, "\n";
enum Color: string {
    case Red = 'r';
    case Blue = 'b';
    public function label(): string {
        return match ($this) {
            self::Red => 'red',
            self::Blue => 'blue',
        };
    }
}
echo Color::Red->label(), "\n";
$scalar = 1;
$matched = false;
switch ($scalar) {
    case E::A:
        $matched = true;
        break;
}
echo $matched ? "false_match\n" : "no_match\n";
--EXPECT--
A
B
red
no_match
