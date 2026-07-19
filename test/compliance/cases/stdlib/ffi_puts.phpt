--TEST--
Stdlib: FFI::cdef + puts via host libffi (#4420)
--SKIPIF--
<?php
if (!extension_loaded('ffi') || !class_exists('FFI', false)) {
    echo "skip host ext/ffi not available\n";
}
?>
--FILE--
<?php
echo class_exists('FFI') ? "class=yes\n" : "class=no\n";
echo extension_loaded('ffi') ? "ext=yes\n" : "ext=no\n";
try {
    FFI::cdef('not valid c!!!@@@');
    echo "bad_cdef=accepted\n";
} catch (FFI\ParserException $e) {
    echo "bad_cdef=ParserException\n";
} catch (FFI\Exception $e) {
    echo "bad_cdef=Exception\n";
} catch (Throwable $e) {
    echo "bad_cdef=", get_class($e), "\n";
}
$ffi = FFI::cdef('int puts(const char *s);');
echo is_object($ffi) ? "cdef=object\n" : "cdef=fail\n";
try {
    $bad = FFI::cdef('int nosuch_phpc_ffi_symbol_xyz(void);');
    $bad->nosuch_phpc_ffi_symbol_xyz();
    echo "missing=accepted\n";
} catch (FFI\Exception $e) {
    echo "missing=Exception\n";
} catch (Throwable $e) {
    echo "missing=", get_class($e), "\n";
}
echo "ready\n";
// C stdout (puts) must be last — avoid interleaving with PHP echo buffers (#4420).
$ffi->puts('hello from ffi');
?>
--EXPECT--
class=yes
ext=yes
bad_cdef=ParserException
cdef=object
missing=Exception
ready
hello from ffi
