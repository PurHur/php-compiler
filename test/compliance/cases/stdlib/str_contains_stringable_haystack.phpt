--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() Stringable haystack under strict_types (#16925)
--FILE--
<?php
declare(strict_types=1);

class C
{
    public function __toString(): string
    {
        return 'obj';
    }
}

var_export(str_contains(new C(), 'obj'));
echo "\n";
var_export(str_starts_with(new C(), 'obj'));
echo "\n";
var_export(str_ends_with(new C(), 'obj'));
echo "\n";

class NoToString
{
}

try {
    str_contains(new NoToString(), 'x');
    echo "no-tostring-uncaught\n";
} catch (TypeError $e) {
    echo "no-tostring=TypeError\n";
}
--EXPECT--
true
true
true
no-tostring=TypeError
