<?php

declare(strict_types=1);

$table = get_html_translation_table(ENT_QUOTES | ENT_SUBSTITUTE);
if (!isset($table["'"]) || '&#039;' !== $table["'"]) {
    echo 'apos_fail:', var_export($table["'"] ?? false, true), "\n";
    exit(1);
}
$t2 = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
if (!isset($t2["'"]) || '&#039;' !== $t2["'"]) {
    echo 'apos_fail_two_arg:', var_export($t2["'"] ?? false, true), "\n";
    exit(1);
}
echo "apos_ok\n";
