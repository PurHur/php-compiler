--TEST--
ext uuid parse/unparse/compare/type surface (issue #22228)
--FILE--
<?php
foreach (['uuid_is_valid','uuid_parse','uuid_unparse','uuid_compare','uuid_is_null','uuid_type','uuid_variant','uuid_time','uuid_mac','uuid_generate_md5','uuid_generate_sha1'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
echo (int) defined('UUID_TYPE_NULL'), "\n";
echo (int) defined('UUID_VARIANT_DCE'), "\n";

$nil = '00000000-0000-0000-0000-000000000000';
echo (int) uuid_is_valid($nil), "\n";
echo (int) uuid_is_valid('not-a-uuid'), "\n";
echo (int) uuid_is_null($nil), "\n";
echo uuid_type($nil), "\n";

$id = uuid_create(UUID_TYPE_RANDOM);
$bin = uuid_parse($id);
echo strlen($bin), "\n";
echo (int) (strtolower($id) === uuid_unparse($bin)), "\n";
echo uuid_compare($id, $id), "\n";
echo uuid_type($id), "\n";
echo uuid_variant($id), "\n";

$t = uuid_create(UUID_TYPE_TIME);
echo uuid_type($t), "\n";
echo uuid_variant($t), "\n";
$mac = uuid_mac($t);
echo strlen($mac), "\n";
echo (int) preg_match('/^[0-9a-f]{12}$/', $mac), "\n";
$ts = uuid_time($t);
echo (int) ($ts > 1600000000 && $ts < 2000000000), "\n";

try {
    uuid_parse('bad');
    echo "bad\n";
} catch (ValueError $e) {
    echo "parse_err\n";
}
try {
    uuid_mac($id);
    echo "badmac\n";
} catch (ValueError $e) {
    echo "mac_err\n";
}
?>
--EXPECT--
uuid_is_valid=1
uuid_parse=1
uuid_unparse=1
uuid_compare=1
uuid_is_null=1
uuid_type=1
uuid_variant=1
uuid_time=1
uuid_mac=1
uuid_generate_md5=1
uuid_generate_sha1=1
1
1
1
0
1
-1
16
1
0
4
1
1
1
12
1
1
parse_err
mac_err
