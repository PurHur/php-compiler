--TEST--
Language: match/switch strict identity — scalar subject must not match enum case arm (#9797, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

var_dump(match (1) {
    E::A => 'a',
    default => 'd',
});

switch (1) {
    case E::A:
        echo "switch matched\n";
        break;
    default:
        echo "switch default\n";
}

echo match (E::A) {
    E::A => 'ok',
}, "\n";
--EXPECT--
string(1) "d"
switch default
ok
