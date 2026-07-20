<?php
// #21188 / re-#20110 — parse_url(null) soft-null under PHP_COMPILER_PROFILE=8.4 (ext/standard/url.c)
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
try {
    var_export(parse_url(null));
    echo " parse_url ok\n";
} catch (Throwable $e) {
    echo get_class($e), ' parse_url: ', $e->getMessage(), "\n";
}
try {
    var_export(md5(null));
    echo " md5 ok\n";
} catch (Throwable $e) {
    echo get_class($e), ' md5: ', $e->getMessage(), "\n";
}
