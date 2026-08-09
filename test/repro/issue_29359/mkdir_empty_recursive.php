<?php
set_error_handler(function ($errno, $errstr) {
    fwrite(STDERR, "WARN: $errstr\n");
    return true;
});
foreach ([[false], [true]] as $rec) {
    $r = mkdir('', 0777, $rec[0]);
    echo 'recursive=', $rec[0] ? 'true' : 'false', ' => ', var_export($r, true), "\n";
}
