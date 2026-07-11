<?php

declare(strict_types=1);

class Target13353 {}

$warnCount = 0;

if (false !== class_alias('NoSuchClass', 'AliasMissing13353')) {
    echo "fail: missing class should return false\n";
    exit(1);
}
$last = error_get_last();
if (null === $last || !str_contains($last['message'], 'Class "NoSuchClass" not found')) {
    echo "fail: missing class warning\n";
    exit(1);
}
++$warnCount;

if (true !== class_alias('Target13353', 'AliasDup13353')) {
    echo "fail: first alias should succeed\n";
    exit(1);
}

if (false !== class_alias('Target13353', 'AliasDup13353')) {
    echo "fail: duplicate alias should return false\n";
    exit(1);
}
$last = error_get_last();
if (null === $last || !str_contains($last['message'], 'Cannot declare class AliasDup13353')) {
    echo "fail: duplicate alias warning\n";
    exit(1);
}
++$warnCount;

echo 2 === $warnCount ? "ok\n" : 'fail: warn='.$warnCount."\n";
