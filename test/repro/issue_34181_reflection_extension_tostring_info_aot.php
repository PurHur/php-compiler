<?php
/**
 * AOT: ReflectionExtension::__toString / info — thin proxy (#34181).
 * Zend/VM: cast non-empty Extension dump; info prints "support => enabled".
 * AOT pre-fix: cast fatal; info() empty.
 */
$e = new ReflectionExtension('date');
$castOk = '0';
try {
    $s = (string) $e;
    $castOk = strlen($s) > 20 && str_contains($s, 'Extension [') && str_contains($s, 'date') ? '1' : '0';
} catch (Throwable $t) {
    $castOk = 'fatal';
}
echo 'cast_ok=', $castOk, "\n";

ob_start();
$e->info();
$info = (string) ob_get_clean();
echo 'info_len=', strlen($info), ' support=', str_contains($info, 'date support => enabled') ? '1' : '0', "\n";
