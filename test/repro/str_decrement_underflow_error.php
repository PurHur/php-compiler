<?php

declare(strict_types=1);

try {
    $s = 'A';
    str_decrement($s);
} catch (Error $e) {
    echo "Error\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
