--TEST--
iconv_strlen/substr/strpos/strrpos null — E_DEPRECATED + coerce on 8.4 (#21197, reverts #20208)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
foreach ([
    'strlen' => static fn () => iconv_strlen(null),
    'substr' => static fn () => iconv_substr(null, 0, 1),
    'strpos' => static fn () => iconv_strpos(null, 'a'),
    'strrpos' => static fn () => iconv_strrpos(null, 'a'),
    'needle' => static fn () => iconv_strpos('ab', null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo $label.'=', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': TypeError'."\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 5), "\n";
?>
--EXPECT--
strlen=0
substr=''
strpos=false
strrpos=false
needle=false
depr=1
