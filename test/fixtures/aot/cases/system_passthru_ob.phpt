--TEST--
AOT system()/passthru() route child stdout through active output buffer (#13251)
--FILE--
<?php
declare(strict_types=1);

ob_start();
$systemLast = system('printf "sysline\n"');
$systemBuf = ob_get_clean();
$systemObOk = 0;
if ('sysline' === $systemLast) {
    if ("sysline\n" === $systemBuf) {
        $systemObOk = 1;
    }
}

ob_start();
passthru('printf "pasline\n"');
$passthruBuf = ob_get_clean();
$passthruObOk = 0;
if ("pasline\n" === $passthruBuf) {
    $passthruObOk = 1;
}

echo 'system_ob_ok=', $systemObOk, "\n";
echo 'passthru_ob_ok=', $passthruObOk, "\n";
--EXPECT--
system_ob_ok=1
passthru_ob_ok=1
