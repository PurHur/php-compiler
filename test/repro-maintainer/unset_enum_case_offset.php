<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

$a = [1 => 10, 2 => 20];
try {
    unset($a[E::A]);
    echo "no error\n";
    var_export($a);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

// Normal int-key unset still works.
$b = [1 => 10, 2 => 20];
unset($b[1]);
echo 'int-unset: ', count($b), "\n";
