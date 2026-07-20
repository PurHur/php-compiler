--TEST--
stdlib nl2br()/convert_uuencode()/convert_uudecode() null — DEP+coerce on 8.4 (#21420, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    $kind = ($no === E_DEPRECATED) ? 'DEP' : 'WARN';
    echo "{$kind}:{$msg}\n";
    return true;
});
$r1 = nl2br(null);
echo "nl2br:[" . var_export($r1, true) . "]\n";
$r2 = convert_uuencode(null);
echo "convert_uuencode:[" . var_export($r2, true) . "]\n";
$r3 = convert_uudecode(null);
echo "convert_uudecode:[" . var_export($r3, true) . "]\n";
?>
--EXPECT--
DEP:nl2br(): Passing null to parameter #1 ($string) of type string is deprecated
nl2br:['']
DEP:convert_uuencode(): Passing null to parameter #1 ($string) of type string is deprecated
convert_uuencode:['`
']
DEP:convert_uudecode(): Passing null to parameter #1 ($string) of type string is deprecated
WARN:convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string
convert_uudecode:[false]
