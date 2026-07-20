--TEST--
stdlib ctype_*() — enum case operands return false not backing coercion (#8962, ext/standard/ctype.c)
--FILE--
<?php
enum E: string { case A = 'abc'; case B = '123'; }

$tests = [
    'ctype_alpha' => static fn () => ctype_alpha(E::A),
    'ctype_digit' => static fn () => ctype_digit(E::B),
    'ctype_alnum' => static fn () => ctype_alnum(E::A),
    'ctype_lower' => static fn () => ctype_lower(E::A),
    'ctype_upper' => static fn () => ctype_upper(E::A),
    'ctype_xdigit' => static fn () => ctype_xdigit(E::B),
    'ctype_space' => static fn () => ctype_space(E::A),
    'ctype_cntrl' => static fn () => ctype_cntrl(E::A),
    'ctype_graph' => static fn () => ctype_graph(E::A),
    'ctype_print' => static fn () => ctype_print(E::A),
    'ctype_punct' => static fn () => ctype_punct(E::A),
];

foreach ($tests as $name => $fn) {
    echo $name, ':', (int) $fn(), "\n";
}
echo (int) ctype_alpha('abc'), "\n";
--EXPECT--
ctype_alpha:0
ctype_digit:0
ctype_alnum:0
ctype_lower:0
ctype_upper:0
ctype_xdigit:0
ctype_space:0
ctype_cntrl:0
ctype_graph:0
ctype_print:0
ctype_punct:0
1
