--TEST--
stdlib quoted_printable_encode/decode — TypeError for non-string operands (#4596, ext/standard/quot_print.c)
--FILE--
<?php
foreach (['quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    try {
        $fn([]);
        echo "$fn: no throw\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
try {
    quoted_printable_encode(new stdClass());
    echo "encode_object: no throw\n";
} catch (TypeError $e) {
    echo "encode_object: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
quoted_printable_encode: quoted_printable_encode(): Argument #1 ($string) must be of type string, array given
quoted_printable_decode: quoted_printable_decode(): Argument #1 ($string) must be of type string, array given
encode_object: quoted_printable_encode(): Argument #1 ($string) must be of type string, stdClass given
