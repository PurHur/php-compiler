<?php

@unlink('/tmp/phpc_phar_ser_probe.tar');
$p = new PharData('/tmp/phpc_phar_ser_probe.tar');
$p['x.txt'] = 'hi';
foreach (['PharData' => $p, 'PharFileInfo' => $p['x.txt']] as $label => $o) {
    try {
        serialize($o);
        echo $label, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
foreach (['PharData' => 'O:8:"PharData":0:{}', 'PharFileInfo' => 'O:12:"PharFileInfo":0:{}'] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
@unlink('/tmp/phpc_phar_ser_probe.tar');
