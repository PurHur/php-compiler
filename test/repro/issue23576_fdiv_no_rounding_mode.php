<?php
declare(strict_types=1);

echo 'two=', fdiv(5.0, 2.0), "\n";
try {
    echo 'three=', fdiv(5.0, 2.0, 1), "\n";
} catch (ArgumentCountError $e) {
    echo 'ArgumentCountError:', $e->getMessage(), "\n";
}
