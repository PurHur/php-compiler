<?php
// #20110 — parse_url(null) TypeError under PHP_COMPILER_PROFILE=8.4 (ext/standard/url.c)
try {
    var_export(parse_url(null));
    echo " parse_url uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ' parse_url: ', $e->getMessage(), "\n";
}
try {
    var_export(md5(null));
    echo " md5 uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ' md5: ', $e->getMessage(), "\n";
}
