--TEST--
JIT: match on enum subject throws UnhandledMatchError with Enum::Case (issue #29248, re-#5448, #6740)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    match (E::A) { E::B => 'b' };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
UnhandledMatchError: Unhandled match case E::A
