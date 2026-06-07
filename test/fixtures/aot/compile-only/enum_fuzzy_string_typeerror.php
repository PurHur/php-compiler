<?php
// Compile-only (#5740): fuzzy string builtins must lower enum-case TypeError guards for AOT.
enum E: string { case A = 'hello'; }
$p = 0;
try {
    similar_text(E::A, 'hello', $p);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    str_word_count(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    metaphone(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    soundex(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
