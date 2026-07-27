--TEST--
stdlib usort()/uasort()/uksort() invokable/array/user-string callables (#23550, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

function cmp_asc($a, $b) {
    return $a <=> $b;
}

class Cmp {
    public function __invoke($a, $b) {
        return $a <=> $b;
    }

    public static function s($a, $b) {
        return $a <=> $b;
    }

    public function m($a, $b) {
        return $a <=> $b;
    }
}

foreach ([
    'invokable' => new Cmp,
    'array_static' => ['Cmp', 's'],
    'array_instance' => [new Cmp, 'm'],
    'string_fn' => 'cmp_asc',
    'closure' => fn($a, $b) => $a <=> $b,
    'strcmp' => 'strcmp',
] as $label => $cb) {
    $a = [3, 1, 2];
    usort($a, $cb);
    echo "usort $label: ", json_encode($a), "\n";

    $b = ['c' => 3, 'a' => 1, 'b' => 2];
    uasort($b, $cb);
    echo "uasort $label: ", json_encode($b), "\n";

    $c = ['c' => 3, 'a' => 1, 'b' => 2];
    uksort($c, $cb);
    echo "uksort $label: ", json_encode($c), "\n";
}
--EXPECT--
usort invokable: [1,2,3]
uasort invokable: {"a":1,"b":2,"c":3}
uksort invokable: {"a":1,"b":2,"c":3}
usort array_static: [1,2,3]
uasort array_static: {"a":1,"b":2,"c":3}
uksort array_static: {"a":1,"b":2,"c":3}
usort array_instance: [1,2,3]
uasort array_instance: {"a":1,"b":2,"c":3}
uksort array_instance: {"a":1,"b":2,"c":3}
usort string_fn: [1,2,3]
uasort string_fn: {"a":1,"b":2,"c":3}
uksort string_fn: {"a":1,"b":2,"c":3}
usort closure: [1,2,3]
uasort closure: {"a":1,"b":2,"c":3}
uksort closure: {"a":1,"b":2,"c":3}
usort strcmp: [1,2,3]
uasort strcmp: {"a":1,"b":2,"c":3}
uksort strcmp: {"a":1,"b":2,"c":3}
