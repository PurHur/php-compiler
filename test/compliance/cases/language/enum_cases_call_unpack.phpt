--TEST--
Language: call argument unpacking on enum case arrays preserves cases (#5568, zend_execute.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
function names(...$args): void {
    foreach ($args as $case) {
        if (!$case instanceof E) {
            echo '?', "\n";
            continue;
        }
        echo $case->name, "\n";
    }
}
names(...E::cases());
names(...[E::A, E::B]);
--EXPECT--
A
B
A
B
