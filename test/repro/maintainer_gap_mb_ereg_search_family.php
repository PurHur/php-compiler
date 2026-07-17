<?php
/**
 * #20024 — mb_ereg_search* / mb_ereg_match / mb_eregi_replace / mb_ereg_replace_callback
 */
declare(strict_types=1);

echo 'exists_search_init=', function_exists('mb_ereg_search_init') ? '1' : '0', "\n";
echo 'exists_match=', function_exists('mb_ereg_match') ? '1' : '0', "\n";
echo 'exists_eregi_replace=', function_exists('mb_eregi_replace') ? '1' : '0', "\n";
echo 'exists_replace_cb=', function_exists('mb_ereg_replace_callback') ? '1' : '0', "\n";

mb_ereg_search_init('abc123def', '[0-9]+');
echo 'search=', var_export(mb_ereg_search(), true), "\n";
echo 'getregs=', var_export(mb_ereg_search_getregs(), true), "\n";
echo 'getpos=', var_export(mb_ereg_search_getpos(), true), "\n";

echo 'match=', var_export(mb_ereg_match('he.*o', 'hello'), true), "\n";
echo 'eregi_replace=', var_export(mb_eregi_replace('WORLD', 'Earth', 'Hello World'), true), "\n";
echo 'replace_cb=', var_export(
    mb_ereg_replace_callback('W.', static function (array $m): string {
        return strtoupper($m[0]);
    }, 'Hello World'),
    true
), "\n";
