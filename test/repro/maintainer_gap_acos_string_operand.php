<?php
declare(strict_types=1);

try {
    $r = acos('0.5');
    echo 'result=', $r, "\n";
} catch (\TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
