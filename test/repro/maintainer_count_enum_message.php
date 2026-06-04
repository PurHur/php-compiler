<?php

enum E
{
    case A;
}

try {
    count(E::A);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
