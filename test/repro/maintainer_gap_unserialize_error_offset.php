<?php

declare(strict_types=1);

/**
 * unserialize() malformed array header — notice offset must match Zend (#18471).
 */

$payload = 'a:'.str_repeat('i:0;', 3).'i:0;';
$len = \strlen($payload);

$result = @unserialize($payload);
if (false !== $result) {
    fwrite(STDERR, "FAIL: expected false\n");
    exit(1);
}

$last = error_get_last();
$expected = 'unserialize(): Error at offset 0 of '.$len.' bytes';
if (!\is_array($last) || ($last['message'] ?? '') !== $expected) {
    fwrite(STDERR, 'FAIL: notice mismatch: '.var_export($last['message'] ?? null, true)."\n");
    exit(1);
}

echo "ok\n";
