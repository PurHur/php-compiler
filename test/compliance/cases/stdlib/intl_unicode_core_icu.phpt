--TEST--
stdlib grapheme_*/idn_to_*/Normalizer advertise with host php-intl (#20630, #22691)
--FILE--
<?php
declare(strict_types=1);

echo 'intl=', (int) extension_loaded('intl'), "\n";
echo 'grapheme=', (int) function_exists('grapheme_strlen'), "\n";
echo 'idn=', (int) function_exists('idn_to_ascii'), "\n";
echo 'normalizer_fn=', (int) function_exists('normalizer_normalize'), "\n";
echo 'Normalizer=', (int) class_exists('Normalizer', false), "\n";

echo 'clusters=', grapheme_strlen("\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8"), "\n"; // 🇺🇸
echo 'title=', grapheme_strlen('café'), "\n";

if (function_exists('idn_to_ascii')) {
    echo 'idn=', idn_to_ascii('bücher.de', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46), "\n";
} else {
    echo "idn=skip\n";
}

$composed = "\xC3\xA9";
$decomposed = "e\xCC\x81";
echo 'nfc=', bin2hex(normalizer_normalize($decomposed, Normalizer::FORM_C)), "\n";
echo 'stable=', (int) (normalizer_normalize($composed, Normalizer::FORM_C) === $composed), "\n";
?>
--EXPECT--
intl=1
grapheme=1
idn=1
normalizer_fn=1
Normalizer=1
clusters=1
title=4
idn=xn--bcher-kva.de
nfc=c3a9
stable=1
