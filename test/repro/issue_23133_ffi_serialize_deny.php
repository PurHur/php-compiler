<?php

$ffi = FFI::cdef('int abs(int x);');
foreach ([
    'FFI' => $ffi,
    'FFI\\CData' => $ffi->new('int'),
    'FFI\\CType' => FFI::type('int'),
] as $label => $o) {
    try {
        serialize($o);
        echo $label, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
foreach ([
    'FFI' => 'O:3:"FFI":0:{}',
    'FFI\\CData' => 'O:9:"FFI\\CData":0:{}',
    'FFI\\CType' => 'O:9:"FFI\\CType":0:{}',
] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
