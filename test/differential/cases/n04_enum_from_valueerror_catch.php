<?php
// #24219: ValueError from Enum::from() must be catchable under AOT (same as Zend/VM).
// Bounding: try/catch itself works; manually thrown ValueError and intdiv ArithmeticError are caught.
enum S: string { case A = 'a'; case B = 'b'; }
try {
    S::from('zz');
    echo "no throw\n";
} catch (\ValueError $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
