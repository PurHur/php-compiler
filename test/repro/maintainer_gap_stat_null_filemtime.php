<?php

if (false !== filemtime(null)) {
    echo "fail: filemtime(null) must be false\n";
    exit(1);
}
if (false !== stat(null)) {
    echo "fail: stat(null) must be false\n";
    exit(1);
}
if (false !== lstat(null)) {
    echo "fail: lstat(null) must be false\n";
    exit(1);
}
echo "ok\n";
