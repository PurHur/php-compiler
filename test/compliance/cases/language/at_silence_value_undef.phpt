--TEST--
Language: value-consuming @$undef is silenced; later bare read still warns (#31881)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $no, string $str): bool {
    if (0 !== error_reporting()) {
        echo 'LIVE:', $str, "\n";
    }
    return true;
});

echo var_export(@$undef_echo, true), "\n";
echo @$undef_plus + 1, "\n";
echo strlen(@$undef_call), "\n";
$a = @$undef_assign;
var_dump($a);
@$undef_bare;
print @$undef_print;
echo "print-ok\n";
echo $undef_echo;
echo "done\n";
--EXPECT--
NULL
1
LIVE:strlen(): Passing null to parameter #1 ($string) of type string is deprecated
0
NULL
print-ok
LIVE:Undefined variable $undef_echo
done
