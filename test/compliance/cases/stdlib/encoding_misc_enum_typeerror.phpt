--TEST--
stdlib parse_str/quoted_printable_* — backed enum case TypeError (#5890, php-src-strict)
--FILE--
<?php
enum Es: string { case A = 'x'; }
$out = [];
try {
    parse_str(Es::A, $out);
    echo "parse_str uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    quoted_printable_encode(Es::A);
    echo "qp_encode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    quoted_printable_decode(Es::A);
    echo "qp_decode uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$round = quoted_printable_decode(quoted_printable_encode("ok"));
echo $round, "\n";
--EXPECT--
parse_str(): Argument #1 ($string) must be of type string, Es given
quoted_printable_encode(): Argument #1 ($string) must be of type string, Es given
quoted_printable_decode(): Argument #1 ($string) must be of type string, Es given
ok
