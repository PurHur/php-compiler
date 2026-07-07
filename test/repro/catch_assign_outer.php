<?php

declare(strict_types=1);

$error = '';
try {
    throw new Exception();
} catch (Exception $e) {
    $error = 'caught';
}
echo "error=$error\n";
