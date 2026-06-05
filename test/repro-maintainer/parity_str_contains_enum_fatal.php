<?php
declare(strict_types=1);

enum Es: string {
    case A = 'ab';
}

try {
    str_starts_with(Es::A, 'a');
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
