<?php

declare(strict_types=1);

$fail = 0;

$got = substr(sprintf('%o', 33188), -4);
if ('0644' !== $got) {
    echo "FAIL sprintf: got $got\n";
    ++$fail;
}

$got = substr(dechex(255), -2);
if ('ff' !== $got) {
    echo "FAIL dechex: got $got\n";
    ++$fail;
}

$got = substr(str_pad('1', 5, '0'), -3);
if ('000' !== $got) {
    echo "FAIL str_pad: got $got\n";
    ++$fail;
}

$got = substr(sprintf('%s', 'abcdef'), 0, 2);
if ('ab' !== $got) {
    echo "FAIL positive control: got $got\n";
    ++$fail;
}

$tmp = tempnam(sys_get_temp_dir(), 'phpc');
if (false !== $tmp) {
    chmod($tmp, 0644);
    $got = substr(sprintf('%o', fileperms($tmp)), -4);
    if ('0644' !== $got) {
        echo "FAIL fileperms: got $got\n";
        ++$fail;
    }
    unlink($tmp);
}

exit($fail === 0 ? 0 : 1);
