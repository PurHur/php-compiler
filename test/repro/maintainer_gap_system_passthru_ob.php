<?php

declare(strict_types=1);

ob_start();
$systemLast = system('printf "sysline\n"');
$systemBuf = ob_get_clean();
$systemObOk = ('sysline' === $systemLast && "sysline\n" === $systemBuf) ? 1 : 0;

ob_start();
passthru('printf "pasline\n"');
$passthruBuf = ob_get_clean();
$passthruObOk = ("pasline\n" === $passthruBuf) ? 1 : 0;

echo 'system_ob_ok=', $systemObOk, "\n";
echo 'passthru_ob_ok=', $passthruObOk, "\n";

if (1 !== $systemObOk || 1 !== $passthruObOk) {
    exit(1);
}
