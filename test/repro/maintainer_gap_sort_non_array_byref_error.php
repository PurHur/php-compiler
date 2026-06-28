<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';
$noticeCount = 0;

foreach (['sort', 'ksort', 'arsort', 'natcasesort'] as $fn) {
    try {
        $fn(new stdClass());
        echo "fail: {$fn}(stdClass) uncaught\n";
        exit(1);
    } catch (\TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array')) {
            echo "fail: {$fn}(stdClass) unexpected: {$e->getMessage()}\n";
            exit(1);
        }
    } catch (\Throwable $e) {
        echo "fail: {$fn}(stdClass) threw ".get_class($e).': '.$e->getMessage()."\n";
        exit(1);
    }
    $last = error_get_last();
    if (null === $last || !str_contains($last['message'], $expectedNotice)) {
        echo "fail: {$fn}(stdClass) missing E_NOTICE\n";
        exit(1);
    }
    ++$noticeCount;
}

if (4 !== $noticeCount) {
    echo "fail: expected 4 notices, got {$noticeCount}\n";
    exit(1);
}

echo "ok\n";
