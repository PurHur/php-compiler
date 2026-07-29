--TEST--
hexdec/octdec/bindec/base_convert invalid digits emit E_DEPRECATED (#24950, ext/standard/math.c)
--FILE--
<?php
error_reporting(E_ALL);
foreach ([
    'hexdec' => static fn () => hexdec('1g'),
    'octdec' => static fn () => octdec('18'),
    'bindec' => static fn () => bindec('102'),
    'base_convert' => static fn () => base_convert('1g', 16, 10),
] as $name => $fn) {
    $seen = [];
    set_error_handler(static function (int $no, string $str) use (&$seen): bool {
        $seen[] = [$no, $str];
        return true;
    });
    $value = $fn();
    restore_error_handler();
    echo $name, '=', var_export($value, true), ' deps=', json_encode($seen), "\n";
}
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
echo 'hexdec_ok=', var_export(hexdec('ff'), true), ' deps=', json_encode($seen), "\n";
restore_error_handler();
--EXPECT--
hexdec=1 deps=[[8192,"Invalid characters passed for attempted conversion, these have been ignored"]]
octdec=1 deps=[[8192,"Invalid characters passed for attempted conversion, these have been ignored"]]
bindec=2 deps=[[8192,"Invalid characters passed for attempted conversion, these have been ignored"]]
base_convert='1' deps=[[8192,"Invalid characters passed for attempted conversion, these have been ignored"]]
hexdec_ok=255 deps=[]
