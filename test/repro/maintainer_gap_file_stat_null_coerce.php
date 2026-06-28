<?php

declare(strict_types=1);

$fail = 0;

if (false !== file_exists(null)) {
    echo "fail: file_exists(null)\n";
    ++$fail;
}
if (false !== is_file(null)) {
    echo "fail: is_file(null)\n";
    ++$fail;
}
if (false !== is_dir(null)) {
    echo "fail: is_dir(null)\n";
    ++$fail;
}
if (false !== filesize(null)) {
    echo "fail: filesize(null)\n";
    ++$fail;
}
$renamed = rename(null, '/tmp/no-such-target-13354');
if (false !== $renamed) {
    echo "fail: rename(null, ...)\n";
    ++$fail;
}

$pi = pathinfo(null);
if (!\is_array($pi) || '' !== ($pi['basename'] ?? 'x') || '' !== ($pi['filename'] ?? 'x')) {
    echo "fail: pathinfo(null)\n";
    ++$fail;
}
if ('' !== basename(null)) {
    echo "fail: basename(null)\n";
    ++$fail;
}
if ('' !== dirname(null)) {
    echo "fail: dirname(null)\n";
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
