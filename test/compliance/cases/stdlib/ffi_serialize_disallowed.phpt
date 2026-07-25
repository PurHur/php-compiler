--TEST--
FFI/FFI\CData/FFI\CType serialize()/unserialize() reject (issue #23133, ext/ffi/ffi.stub.php)
--FILE--
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
--EXPECT--
FFI:serialize:Exception:Serialization of 'FFI' is not allowed
FFI\CData:serialize:Exception:Serialization of 'FFI\CData' is not allowed
FFI\CType:serialize:Exception:Serialization of 'FFI\CType' is not allowed
FFI:unserialize:Exception:Unserialization of 'FFI' is not allowed
FFI\CData:unserialize:Exception:Unserialization of 'FFI\CData' is not allowed
FFI\CType:unserialize:Exception:Unserialization of 'FFI\CType' is not allowed
