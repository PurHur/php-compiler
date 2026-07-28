<?php
// FAILS ON AOT — #24219. The ValueError from Enum::from() is not catchable: the binary dies with
// "PHP Fatal error: Uncaught ValueError" despite the matching catch block. catch (\Throwable) does
// not catch it either.
//
// Bounding evidence: try/catch itself works on AOT — a manually thrown ValueError is caught, and so
// is an ArithmeticError from intdiv(PHP_INT_MIN, -1). It is specific to the throw emitted on the
// from() no-match path.
//
// Note the AOT message also renders a literal \n instead of a newline, the same cosmetic defect as
// g07_inc_resource.
enum S: string { case A = 'a'; case B = 'b'; }
try {
    S::from('zz');
    echo "no throw\n";
} catch (\ValueError $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
