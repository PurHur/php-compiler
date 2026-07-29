<?php
/**
 * Repro for #24662: highlight_string() HTML wire must match Zend.
 *
 * Zend: <code><span…> wrapper, &nbsp; for spaces, no <pre>.
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
    echo "FAIL: should not contain <pre>\n";
    $errors++;
} else {
    echo "OK: no <pre>\n";
}

if (!preg_match('/<code><span/', $z)) {
    echo "FAIL: should match <code><span\n";
    $errors++;
} else {
    echo "OK: <code><span shape\n";
}

exit($errors > 0 ? 1 : 0);
