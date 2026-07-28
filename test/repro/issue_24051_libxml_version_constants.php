<?php
/**
 * #24051 — LIBXML_VERSION / LIBXML_DOTTED_VERSION / LIBXML_LOADED_VERSION (ext/libxml/libxml.c)
 *
 * php-src: REGISTER_LONG_CONSTANT(LIBXML_VERSION) + string dotted + loaded (xmlParserVersion).
 */
foreach (['LIBXML_VERSION', 'LIBXML_DOTTED_VERSION', 'LIBXML_LOADED_VERSION', 'LIBXML_NOERROR'] as $c) {
    if (!defined($c)) {
        echo $c, "=UNDEF\n";
        continue;
    }
    $v = constant($c);
    echo $c, '=', var_export($v, true), ':', gettype($v), "\n";
}
echo 'loaded_matches_version=', LIBXML_LOADED_VERSION === (string) LIBXML_VERSION ? 'yes' : 'no', "\n";
echo 'dotted_shape=', preg_match('/^\d+\.\d+(\.\d+)?/', LIBXML_DOTTED_VERSION) ? 'ok' : 'bad', "\n";
