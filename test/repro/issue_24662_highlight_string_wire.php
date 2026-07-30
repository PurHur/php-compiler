<?php
/**
 * Repro for #24662 / #25063: reference profile emits Zend 8.2 highlight wire.
 * Forward PROFILE=8.4 modern wire is covered by issue_24874_highlight_string_wire.php.
 */

$errors = 0;

$z = highlight_string("<?php echo 1;", true);
if (!str_contains($z, "&nbsp;")) {
    echo "FAIL: missing &nbsp;\n";
    $errors++;
} else {
    echo "OK: contains &nbsp;\n";
}
if (str_contains($z, "<pre>")) {
    echo "FAIL: unexpected <pre>\n";
    $errors++;
} else {
    echo "OK: no <pre>\n";
}
if (!preg_match('/<code><span/', $z)) {
    echo "FAIL: missing <code><span\n";
    $errors++;
} else {
    echo "OK: <code><span shape\n";
}

exit($errors > 0 ? 1 : 0);
