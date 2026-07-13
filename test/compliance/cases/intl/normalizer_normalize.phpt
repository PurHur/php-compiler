--TEST--
normalizer_normalize() NFC Latin-1 é — ext/intl (#5153, php-src ext/intl/normalizer)
--SKIPIF--
<?php
if (!function_exists('normalizer_normalize')) {
    die('skip normalizer not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

$composed = "\xC3\xA9";
$decomposed = "e\xCC\x81";

echo bin2hex(normalizer_normalize($decomposed, Normalizer::FORM_C)), "\n";
echo normalizer_normalize($composed, Normalizer::FORM_C) === $composed ? "stable\n" : "changed\n";
echo normalizer_is_normalized($composed, Normalizer::FORM_C) ? "norm\n" : "not\n";
echo normalizer_is_normalized($decomposed, Normalizer::FORM_C) ? "decomposed_norm\n" : "decomposed_not\n";
?>
--EXPECT--
c3a9
stable
norm
decomposed_not
