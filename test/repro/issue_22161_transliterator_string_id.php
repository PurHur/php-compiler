<?php
// Repro #22161 — transliterator_transliterate(Transliterator|string)
$id = 'Any-Latin; Latin-ASCII';
echo 'str=', transliterator_transliterate($id, '東京'), "\n";
$tr = transliterator_create($id);
echo 'obj=', is_object($tr) ? transliterator_transliterate($tr, '東京') : '-', "\n";
$bad = @transliterator_transliterate('Not-A-Real-ID-XYZ', '東京');
echo 'bad=', var_export($bad, true), "\n";
echo 'err=', intl_get_error_message(), "\n";
try {
    transliterator_transliterate([], 'x');
    echo "array_ok\n";
} catch (TypeError $e) {
    echo 'array_te=', $e->getMessage(), "\n";
}
