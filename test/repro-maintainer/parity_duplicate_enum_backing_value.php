<?php

enum E: int {
    case A = 1;
    case B = 1;
}

echo "before\n";
try {
    echo E::A->name, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "after\n";
