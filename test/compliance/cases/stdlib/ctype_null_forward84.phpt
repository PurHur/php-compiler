--TEST--
stdlib ctype_*(null) TypeError on 8.4 forward profile (#20252, ext/ctype/ctype.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$fns = [
    'ctype_alnum',
    'ctype_alpha',
    'ctype_cntrl',
    'ctype_digit',
    'ctype_graph',
    'ctype_lower',
    'ctype_print',
    'ctype_punct',
    'ctype_space',
    'ctype_upper',
    'ctype_xdigit',
    'ctype_blank',
];
foreach ($fns as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_string=', (int) ctype_alnum('abc'), "\n";
?>
--EXPECT--
ctype_alnum: ctype_alnum(): Argument #1 ($text) must be of type string, null given
ctype_alpha: ctype_alpha(): Argument #1 ($text) must be of type string, null given
ctype_cntrl: ctype_cntrl(): Argument #1 ($text) must be of type string, null given
ctype_digit: ctype_digit(): Argument #1 ($text) must be of type string, null given
ctype_graph: ctype_graph(): Argument #1 ($text) must be of type string, null given
ctype_lower: ctype_lower(): Argument #1 ($text) must be of type string, null given
ctype_print: ctype_print(): Argument #1 ($text) must be of type string, null given
ctype_punct: ctype_punct(): Argument #1 ($text) must be of type string, null given
ctype_space: ctype_space(): Argument #1 ($text) must be of type string, null given
ctype_upper: ctype_upper(): Argument #1 ($text) must be of type string, null given
ctype_xdigit: ctype_xdigit(): Argument #1 ($text) must be of type string, null given
ctype_blank: ctype_blank(): Argument #1 ($text) must be of type string, null given
ok_string=1
