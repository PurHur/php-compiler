<?php

@unlink('/tmp/phpc_dba_ser.db');
$d = dba_open('/tmp/phpc_dba_ser.db', 'n', 'flatfile');
try {
    echo serialize($d), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:14:"Dba\\Connection":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), ':', $e->getMessage(), "\n";
}
