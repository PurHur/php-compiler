<?php
/**
 * Repro #27645 — AOT strtok() continue in a loop must terminate.
 *
 *   ./phpc build -o /tmp/tok test/repro/issue_27645_aot_strtok_loop.php && /tmp/tok
 */
$t = strtok("a b c", " ");
while ($t !== false) {
    echo "[$t]";
    $t = strtok(" ");
}
echo "\nDONE\n";
