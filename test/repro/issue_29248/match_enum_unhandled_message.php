<?php
/**
 * #29248 — UnhandledMatchError on enum subjects names Enum::Case (zend_smart_str.c).
 */
enum E { case A; case B; }
try {
    match (E::B) { E::A => 'a' };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}

enum F: string { case A = 'a'; case B = 'b'; }
try {
    match (F::B) { F::A => 'a' };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}

class C {}
try {
    match (new C) { 1 => 'x' };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}
