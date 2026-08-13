<?php
/**
 * highlight_string / get_meta_tags excess argc → ArgumentCountError (#30723).
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.c
 */
try {
    highlight_string('<?php', true, 3);
    echo "hs_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hs_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hs_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    highlight_string();
    echo "hs_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'hs_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'hs_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    get_meta_tags('/etc/hosts', true, 3);
    echo "gmt_hi:OK\n";
} catch (ArgumentCountError $e) {
    echo 'gmt_hi:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'gmt_hi:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    get_meta_tags();
    echo "gmt_lo:OK\n";
} catch (ArgumentCountError $e) {
    echo 'gmt_lo:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'gmt_lo:', get_class($e), ':', $e->getMessage(), "\n";
}

$html = highlight_string('<?php echo 1;', true);
echo 'ok_hs:', (is_string($html) && '' !== $html) ? '1' : '0', "\n";
$tags = get_meta_tags('test/compliance/cases/stdlib/get_meta_tags_fixture.html', true);
echo 'ok_gmt:', (is_array($tags) && isset($tags['author']) && 'me' === $tags['author']) ? '1' : '0', "\n";
