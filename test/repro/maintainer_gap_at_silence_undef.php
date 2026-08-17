<?php
// Value-consuming `@$undef` (echo / arithmetic / call arg) must not emit Undefined variable.
// Assign RHS, bare statement, and print are already silent (controls; #29132).
error_reporting(E_ALL);

echo "assign-control\n";
$x = @$undef;
echo 'x=', var_export($x, true), "\n";

echo "echo\n";
echo var_export(@$undef2, true), "\n";

echo "plus\n";
echo @$undef3 + 1, "\n";

echo "callarg\n";
echo strlen(@$undef4), "\n";

echo "bare-control\n";
@$undef5;
echo "bare-ok\n";

echo "print-control\n";
print @$undef6;
echo "print-ok\n";
