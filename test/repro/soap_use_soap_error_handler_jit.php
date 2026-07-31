<?php
declare(strict_types=1);

/**
 * Repro #26168 — use_soap_error_handler() under JIT must not throw.
 * Requires host php-soap.
 */
if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap not advertised\n");
    exit(2);
}

$code = <<<'PHP'
<?php
$a = use_soap_error_handler(false);
$b = use_soap_error_handler(true);
$c = use_soap_error_handler();
echo (int)$a, (int)$b, (int)$c;
PHP;

$tmp = sys_get_temp_dir() . '/phpc_use_soap_eh_jit_' . getmypid() . '.php';
file_put_contents($tmp, $code);
$jit = __DIR__ . '/../../bin/jit.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($jit) . ' ' . escapeshellarg($tmp), $code);
@unlink($tmp);
exit($code);
