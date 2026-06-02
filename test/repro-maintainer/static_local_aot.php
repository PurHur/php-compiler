<?php
function counter(): int {
    static $n = 0;
    $n = $n + 1;
    return $n;
}
echo counter(), "\n", counter(), "\n";
