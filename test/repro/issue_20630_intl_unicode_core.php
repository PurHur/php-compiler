<?php
// Repro #20630 — grapheme_*/idn_to_*/Normalizer with ICU-backed ext/intl
foreach (['grapheme_strlen', 'idn_to_ascii', 'normalizer_normalize'] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
echo 'Normalizer ', class_exists('Normalizer') ? 'Y' : 'N', PHP_EOL;
echo 'intl ', extension_loaded('intl') ? 'Y' : 'N', PHP_EOL;
if (!function_exists('grapheme_strlen')) {
    fwrite(STDERR, "fail: grapheme_strlen missing\n");
    exit(1);
}
echo 'clusters=', grapheme_strlen("\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8"), PHP_EOL; // 🇺🇸

