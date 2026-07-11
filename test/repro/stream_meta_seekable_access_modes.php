<?php

declare(strict_types=1);

$m = stream_get_meta_data(tmpfile());
$directBefore = $m['seekable'];
@file_get_contents('/nonexistent-path-for-phpc-18005');
$directAfter = $m['seekable'];
$foreachAfter = null;
foreach ($m as $key => $value) {
    if ('seekable' === $key) {
        $foreachAfter = $value;
    }
}

echo 'direct_before=', var_export($directBefore, true), "\n";
echo 'direct_after=', var_export($directAfter, true), "\n";
echo 'foreach_after=', var_export($foreachAfter, true), "\n";

if (true !== $directBefore || true !== $directAfter || true !== $foreachAfter) {
    exit(1);
}

echo "ok\n";
