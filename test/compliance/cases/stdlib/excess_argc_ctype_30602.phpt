--TEST--
stdlib: ctype_* ArgumentCountError wording (#30602)
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
];
foreach ($fns as $f) {
    try {
        $f('a', 1);
        echo $f, " excess NO_THROW\n";
    } catch (Throwable $e) {
        echo $f, ' excess ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', (ctype_alnum('a') && !ctype_alnum('!')) ? '1' : '0', "\n";
--EXPECT--
ctype_alnum excess ArgumentCountError: ctype_alnum() expects exactly 1 argument, 2 given
ctype_alpha excess ArgumentCountError: ctype_alpha() expects exactly 1 argument, 2 given
ctype_cntrl excess ArgumentCountError: ctype_cntrl() expects exactly 1 argument, 2 given
ctype_digit excess ArgumentCountError: ctype_digit() expects exactly 1 argument, 2 given
ctype_graph excess ArgumentCountError: ctype_graph() expects exactly 1 argument, 2 given
ctype_lower excess ArgumentCountError: ctype_lower() expects exactly 1 argument, 2 given
ctype_print excess ArgumentCountError: ctype_print() expects exactly 1 argument, 2 given
ctype_punct excess ArgumentCountError: ctype_punct() expects exactly 1 argument, 2 given
ctype_space excess ArgumentCountError: ctype_space() expects exactly 1 argument, 2 given
ctype_upper excess ArgumentCountError: ctype_upper() expects exactly 1 argument, 2 given
ctype_xdigit excess ArgumentCountError: ctype_xdigit() expects exactly 1 argument, 2 given
ok=1
