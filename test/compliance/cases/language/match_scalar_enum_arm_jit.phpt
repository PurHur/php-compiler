--TEST--
JIT: match() scalar subject must not match enum-case arms (issue #9566, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    echo match (1) {
        E::A => 'hit',
    };
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo match (E::A) {
    E::A => 'ok',
}, "\n";
?>
--EXPECT--
UnhandledMatchError: Unhandled match case 1
ok
