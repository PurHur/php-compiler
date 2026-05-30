<?php
// Compile-only (#3648); AOT runtime does not yet catch intdiv(1,0).
try {
    intdiv(1, 0);
    echo "no\n";
} catch (DivisionByZeroError $e) {
    echo "caught\n";
}
