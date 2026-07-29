<?php
/**
 * Legacy PROFILE=8.2 highlight wire (#24662 / #24874).
 * Run with: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_24874_highlight_legacy82.php
 */
$z = highlight_string("<?php echo 1;", true);
$errors = 0;
if (!str_contains($z, "&nbsp;")) { echo "FAIL: missing &nbsp;\n"; $errors++; } else { echo "OK: contains &nbsp;\n"; }
if (str_contains($z, "<pre>")) { echo "FAIL: should not contain <pre>\n"; $errors++; } else { echo "OK: no <pre>\n"; }
if (!preg_match('/<code><span/', $z)) { echo "FAIL: should match <code><span\n"; $errors++; } else { echo "OK: <code><span shape\n"; }
exit($errors > 0 ? 1 : 0);
