<?php
set_error_handler(function ($errno, $errstr) {
    fwrite(STDERR, "WARN: $errstr\n");
    return true;
});
echo var_export(chroot(''), true), "\n";
