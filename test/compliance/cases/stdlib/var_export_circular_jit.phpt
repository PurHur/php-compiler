--TEST--
stdlib var_export() circular array — E_WARNING + NULL marker JIT (#11197)
--JIT--
--FILE--
<?php
$c = [];
$c['self'] = &$c;
$warnings = [];
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;
    return true;
});
$r = var_export($c, true);
restore_error_handler();
echo 'warn=', $warnings[0] ?? '', "\n";
echo 'len=', strlen($r), "\n";
echo $r;
--EXPECT--
warn=var_export does not handle circular references
len=27
array (
  'self' => NULL,
)
