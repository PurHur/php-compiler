<?php

declare(strict_types=1);

/**
 * Issue #12202 — get_html_translation_table(HTML_ENTITIES, ENT_HTML5) full table.
 */
$table = get_html_translation_table(HTML_ENTITIES, ENT_HTML5);
$count = count($table);
$euro = $table["\xe2\x82\xac"] ?? null;

if ($count < 1500 || '&euro;' !== $euro) {
    fwrite(STDERR, "fail: count={$count} euro=".var_export($euro, true)."\n");
    exit(1);
}

echo "ok count={$count}\n";
