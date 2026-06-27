<?php

// Repro for #12880 — getimagesizefromstring() GIF bits/channels (php-src ext/standard/image.c).
$gif = base64_decode('R0lGODdhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
$info = getimagesizefromstring($gif);
if (false === $info) {
    echo "fail: getimagesizefromstring returned false\n";
    exit(1);
}
$fail = 0;
if (($info['bits'] ?? null) !== 1) {
    echo 'fail: bits=', var_export($info['bits'] ?? null, true), ", expected 1\n";
    ++$fail;
}
if (($info['channels'] ?? null) !== 3) {
    echo 'fail: channels=', var_export($info['channels'] ?? null, true), ", expected 3\n";
    ++$fail;
}
if (($info['mime'] ?? null) !== 'image/gif') {
    echo 'fail: mime=', var_export($info['mime'] ?? null, true), ", expected image/gif\n";
    ++$fail;
}
if (0 === $fail) {
    echo "ok\n";
}
