<?php
// Issue #23042 — SensitiveParameterValue must not leak via json_encode / var_export.
function f(#[\SensitiveParameter] string $password, string $user) {
    return debug_backtrace();
}
$sp = f('secret', 'bob')[0]['args'][0];
echo 'json=', json_encode($sp), "\n";
$export = str_replace("\n", ' ', var_export($sp, true));
echo 'var_export=', $export, "\n";
echo 'getvalue=', $sp->getValue(), "\n";
ob_start();
var_dump($sp);
$dump = ob_get_clean();
echo 'vardump_has_secret=', (str_contains($dump, 'secret') ? 'yes' : 'no'), "\n";
echo 'export_has_secret=', (str_contains($export, 'secret') ? 'yes' : 'no'), "\n";
