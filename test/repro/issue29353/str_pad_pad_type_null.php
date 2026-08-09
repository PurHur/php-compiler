<?php

error_reporting(E_ALL);

function issue29353_str_pad_pad_type_null_handler($errno, $errstr)
{
    fwrite(STDERR, "ERR[$errno]: $errstr\n");

    return true;
}

set_error_handler('issue29353_str_pad_pad_type_null_handler');
try {
    var_export(str_pad('a', 5, '.', null));
    echo "\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
