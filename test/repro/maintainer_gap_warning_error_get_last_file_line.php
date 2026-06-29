<?php

declare(strict_types=1);

$classAliasLine = __LINE__ + 1;
class_alias('NoSuchClass', 'AliasMissing13407');
$last = error_get_last();
if (null === $last || !str_contains($last['message'], 'Class "NoSuchClass" not found')) {
    echo "fail: missing class warning message\n";
    exit(1);
}
if (($last['file'] ?? '') !== __FILE__ || ($last['line'] ?? 0) !== $classAliasLine) {
    echo 'fail: class_alias file='.var_export($last['file'] ?? null, true)
        .' line='.var_export($last['line'] ?? null, true)
        .' expected '.__FILE__.':'.$classAliasLine."\n";
    exit(1);
}

$userWarnLine = __LINE__ + 1;
@trigger_error('user warning probe', E_USER_WARNING);
$userLast = error_get_last();
if (($userLast['file'] ?? '') !== __FILE__ || ($userLast['line'] ?? 0) !== $userWarnLine) {
    echo "fail: user warning file/line\n";
    exit(1);
}

echo "ok\n";
