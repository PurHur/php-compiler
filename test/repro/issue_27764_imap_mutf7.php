<?php
/**
 * Issue #27764 — imap_utf8_to_mutf7 / imap_mutf7_to_utf8 Modified UTF-7.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_27764_imap_mutf7.php
 *  or: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27764_imap_mutf7.php
 */
declare(strict_types=1);

echo 'ext=', extension_loaded('imap') ? 'Y' : 'N', "\n";
echo 'open=', function_exists('imap_open') ? 'Y' : 'N', "\n";
echo 'mutf7_to_utf8=', function_exists('imap_mutf7_to_utf8') ? 'Y' : 'N', "\n";
echo 'utf8_to_mutf7=', function_exists('imap_utf8_to_mutf7') ? 'Y' : 'N', "\n";

if (function_exists('imap_utf8_to_mutf7') && function_exists('imap_mutf7_to_utf8')) {
    $m = imap_utf8_to_mutf7('Test');
    echo 'round=', imap_mutf7_to_utf8($m), "\n";
    echo 'taest=', imap_utf8_to_mutf7('täst'), "\n";
    $rf = new ReflectionFunction('imap_utf8_to_mutf7');
    echo 'param=', $rf->getParameters()[0]->getName(), "\n";
    echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
}
