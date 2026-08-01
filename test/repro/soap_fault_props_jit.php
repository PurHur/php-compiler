<?php
declare(strict_types=1);

/**
 * Repro #26511 — SoapFault property access under JIT must module-verify.
 * Requires host php-soap so SoapFault is advertised.
 */
if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap not advertised\n");
    exit(2);
}

$code = <<<'PHP'
<?php
$e = new SoapFault('Client', 'boom', 'actor1', 'detail1');
echo 'code=', $e->faultcode, "\n";
echo 'str=', $e->faultstring, "\n";
echo 'actor=', $e->faultactor, "\n";
echo 'detail=', $e->detail, "\n";
echo 'is=', is_soap_fault($e) ? 1 : 0, "\n";
try {
    throw new SoapFault('Server', 'from_throw');
} catch (SoapFault $caught) {
    echo 'caught=', $caught->faultstring, "\n";
}
PHP;

$tmp = sys_get_temp_dir() . '/phpc_soap_fault_props_jit_' . getmypid() . '.php';
file_put_contents($tmp, $code);

$jit = __DIR__ . '/../../bin/jit.php';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($jit) . ' ' . escapeshellarg($tmp), $code);
@unlink($tmp);
exit($code);
