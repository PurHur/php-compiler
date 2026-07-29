<?php
/**
 * Repro for #24662 / #24874: default profile emits PHP 8.3+ highlight wire format.
 *
 * Legacy PROFILE=8.2 shape is covered by VmHighlightTest::testHighlightEngineLegacyProfileMatchesHostZend
 * (putenv inside bin/vm.php does not reach host HighlightEngine).
 */

$errors = 0;

$z = highlight_string("<?php echo 1;", true);
if (!str_contains($z, "<pre><code")) {
    echo "FAIL: missing <pre><code\n";
    $errors++;
} else {
    echo "OK: <pre><code shape\n";
}
if (str_contains($z, "&nbsp;")) {
    echo "FAIL: unexpected &nbsp;\n";
    $errors++;
} else {
    echo "OK: no &nbsp;\n";
}
if (str_contains($z, "<pre>")) {
    echo "OK: has <pre>\n";
} else {
    echo "FAIL: missing <pre>\n";
    $errors++;
}

exit($errors > 0 ? 1 : 0);
