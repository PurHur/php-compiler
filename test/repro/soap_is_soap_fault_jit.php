<?php
declare(strict_types=1);

/**
 * Repro #26167 — is_soap_fault() under JIT must not throw.
 * Requires host php-soap so SoapFault / is_soap_fault are advertised.
 */
if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap not advertised\n");
    exit(2);
}

$code = <<<'PHP'
<?php
$e = new SoapFault('Client', 'boom');
echo is_soap_fault($e) ? '1' : '0';
echo is_soap_fault(new Exception('x')) ? '1' : '0';
echo is_soap_fault(null) ? '1' : '0';
PHP;

$tmp = sys_get_temp_dir() . '/phpc_is_soap_fault_jit_' . getmypid() . '.php';
file_put_contents($tmp, $code);

$jit = __DIR__ . '/../../bin/jit.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($jit) . ' ' . escapeshellarg($tmp), $code);
@unlink($tmp);
exit($code);
