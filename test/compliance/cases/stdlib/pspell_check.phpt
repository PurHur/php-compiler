--TEST--
pspell_new/check/suggest en dictionary (#6294, ext/pspell/pspell.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip no ffi');
if (!function_exists('pspell_new')) die('skip no pspell (libaspell FFI)');
$dict = @pspell_new('en');
if (false === $dict) die('skip no en aspell dictionary');
?>
--FILE--
<?php
declare(strict_types=1);
$dict = pspell_new('en');
echo 'ce=', (int) ($dict instanceof PSpell\Dictionary), "\n";
echo 'colour=', (int) pspell_check($dict, 'colour'), "\n";
echo 'colourr=', (int) pspell_check($dict, 'colourr'), "\n";
$sugs = pspell_suggest($dict, 'colourr');
echo 'sugs=', (int) (is_array($sugs) && count($sugs) > 0), "\n";
echo "ok\n";
?>
--EXPECT--
ce=1
colour=1
colourr=0
sugs=1
ok
