--TEST--
idn_to_ascii()/idn_to_utf8() UTS46 punycode round-trip (#6169, php-src ext/intl/idn/idn.c)
--SKIPIF--
<?php
if (!function_exists('idn_to_ascii') || !function_exists('idn_to_utf8')) {
    die('skip idn builtins not advertised (libidn2/host intl missing)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('idn_to_ascii') ? "ascii_yes\n" : "ascii_no\n";
echo function_exists('idn_to_utf8') ? "utf8_yes\n" : "utf8_no\n";
echo idn_to_ascii('例え.jp'), "\n";
echo idn_to_ascii('münchen.de'), "\n";
echo idn_to_ascii('example.com'), "\n";
$round = idn_to_utf8(idn_to_ascii('例え.jp'));
echo $round === '例え.jp' ? "round_ok\n" : "round_fail:$round\n";
$info = null;
$a = idn_to_ascii('例え.jp', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);
echo $a, "\n";
echo is_array($info) && ($info['errors'] ?? -1) === 0 ? "info_ok\n" : "info_fail\n";
echo idn_to_ascii('') === false ? "empty_false\n" : "empty_bad\n";
?>
--EXPECT--
ascii_yes
utf8_yes
xn--r8jz45g.jp
xn--mnchen-3ya.de
example.com
round_ok
xn--r8jz45g.jp
info_ok
empty_false
