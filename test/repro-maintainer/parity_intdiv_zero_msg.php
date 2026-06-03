<?php
try {
    intdiv(1, 0);
} catch (DivisionByZeroError $e) {
    echo $e->getMessage(), "\n";
}
