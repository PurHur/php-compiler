<?php

declare(strict_types=1);

/**
 * #29268 — empty-path ValueError must match Zend: "Path must not be empty"
 * (php-src main/fopen_wrappers.c / zend_parse_arg_path).
 *
 * Literal '' is fine for VM/JIT (compile-time rejectEmpty throw lands in catch).
 * AOT: see issue29268_path_empty_valueerror_message_aot.php (non-literal + message on fatal).
 */
$expected = 'Path must not be empty';
$checks = [
    'fopen' => static fn () => fopen('', 'r'),
    'file_get_contents' => static fn () => file_get_contents(''),
    'hash_file' => static fn () => hash_file('sha256', ''),
    'file_put_contents' => static fn () => file_put_contents('', 'x'),
];

foreach ($checks as $fn => $call) {
    try {
        $call();
        fwrite(STDERR, "fail: {$fn}(\"\") expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            fwrite(STDERR, "fail: {$fn}: got {$e->getMessage()}\n");
            exit(1);
        }
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
echo "ok\n";
