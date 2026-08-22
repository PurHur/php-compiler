--TEST--
stdlib iptcparse() / iptcembed() (#6104, ext/standard/iptc.c)
--FILE--
<?php
enum E: string { case A = 'a'; }

$iptc = "\x1c\x02\x78\x00\x04Test";
var_export(function_exists('iptcparse'));
echo "\n";
var_export(function_exists('iptcembed'));
echo "\n";
$parsed = iptcparse($iptc);
var_export($parsed);
echo "\n";
var_export(iptcparse('not-iptc'));
echo "\n";

try {
    iptcparse(E::A);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
$path = sys_get_temp_dir().'/phpc_iptc_test_'.getmypid().'.jpg';
file_put_contents($path, $jpeg);
$embedded = iptcembed($iptc, $path);
var_export(is_string($embedded));
echo "\n";
$jpegOk = false;
if (is_string($embedded)) {
    $jpegOk = "\xFF" === $embedded[0] && "\xD8" === $embedded[1];
}
var_export($jpegOk);
echo "\n";
$round = iptcparse($iptc);
var_export($round === $parsed);
echo "\n";
@unlink($path);
--EXPECT--
true
true
array (
  '2#120' => array (
    0 => 'Test',
  ),
)
false
iptcparse(): Argument #1 ($iptc_block) must be of type string, E given
true
true
true
