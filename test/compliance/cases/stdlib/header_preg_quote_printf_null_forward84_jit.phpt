--TEST--
stdlib header/preg_quote/printf null — DEP+coerce on 8.4 JIT (#21234)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }
    return true;
});
$cases = [
    ['header', fn() => (header(null) || true) && 'ok'],
    ['preg_quote', fn() => preg_quote(null)],
    ['printf', fn() => printf(null)],
];
foreach ($cases as [$n, $fn]) {
    $prev = $deps;
    try {
        $r = $fn();
        echo $n, ($deps > $prev ? ' DEP' : ''), ' OK ', var_export($r, true), "\n";
    } catch (\Throwable $e) {
        echo $n, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
header DEP OK true
preg_quote DEP OK ''
printf DEP OK 0
