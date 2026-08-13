<?php
/**
 * str_word_count / htmlspecialchars_decode / get_html_translation_table
 * excess argc → ArgumentCountError (#30720).
 * php-src: ext/standard/string.c / html.c
 */
try {
    str_word_count('a', 0, '', 4);
    echo "swc_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'swc_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'swc_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    str_word_count();
    echo "swc_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'swc_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'swc_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    htmlspecialchars_decode('&lt;', ENT_QUOTES, 3);
    echo "hsd_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hsd_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hsd_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    htmlspecialchars_decode();
    echo "hsd_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hsd_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hsd_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES, 'UTF-8', 4);
    echo "ghtt_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'ghtt_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'ghtt_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

$swc = str_word_count('hello world');
echo 'ok_swc:', (2 === $swc) ? '1' : '0', "\n";
$hsd = htmlspecialchars_decode('&lt;');
echo 'ok_hsd:', ('<' === $hsd) ? '1' : '0', "\n";
$ghtt = get_html_translation_table();
echo 'ok_ghtt:', (is_array($ghtt) && isset($ghtt['<'])) ? '1' : '0', "\n";
