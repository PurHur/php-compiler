<?php

declare(strict_types=1);

class A
{
    public function __wakeup(): void
    {
        throw new Exception('w');
    }
}

$ok = false;
try {
    unserialize('O:1:"A":0:{}');
    $ok = true;
} catch (Exception $e) {
}
var_export($ok);
