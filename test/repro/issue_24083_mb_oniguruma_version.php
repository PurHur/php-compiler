<?php
/**
 * #24083 — MB_ONIGURUMA_VERSION (ext/mbstring/mbstring.c)
 *
 * php-src: REGISTER_STRING_CONSTANT("MB_ONIGURUMA_VERSION", ONIGURUMA_VERSION, …).
 */
echo defined('MB_ONIGURUMA_VERSION') ? 'defined=yes' : 'defined=no', "\n";
echo 'type=', is_string(MB_ONIGURUMA_VERSION) ? 'string' : gettype(MB_ONIGURUMA_VERSION), "\n";
echo 'value=[', MB_ONIGURUMA_VERSION, ']', "\n";
echo 'shape=', preg_match('/^\d+\.\d+(\.\d+)?/', MB_ONIGURUMA_VERSION) ? 'ok' : 'bad', "\n";
echo 'case=', MB_CASE_UPPER, "\n";
