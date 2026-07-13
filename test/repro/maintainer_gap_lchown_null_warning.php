<?php

$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    if (E_WARNING === $errno) {
        $warnings[] = $message;
    }

    return true;
});

@lchown(null, 0);
@lchgrp(null, 0);

restore_error_handler();

$lchownOk = false;
$lchgrpOk = false;
foreach ($warnings as $message) {
    if (str_contains($message, 'lchown()')) {
        $lchownOk = true;
    }
    if (str_contains($message, 'lchgrp()')) {
        $lchgrpOk = true;
    }
}

if (!$lchownOk || !$lchgrpOk) {
    fwrite(STDERR, 'warnings: '.implode(' | ', $warnings)."\n");
    exit(1);
}

echo "ok\n";
