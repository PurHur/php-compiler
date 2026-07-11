--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() Stringable haystack under strict_types (#17993, ext/standard/string.c)
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

try {
    str_contains(new C(), 'obj');
    echo "contains-uncaught\n";
} catch (TypeError $e) {
    echo "contains=TypeError\n";
}

try {
    str_starts_with(new C(), 'obj');
    echo "starts-uncaught\n";
} catch (TypeError $e) {
    echo "starts=TypeError\n";
}

try {
    str_ends_with(new C(), 'obj');
    echo "ends-uncaught\n";
} catch (TypeError $e) {
    echo "ends=TypeError\n";
}

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
contains=TypeError
starts=TypeError
ends=TypeError
no-tostring=TypeError
