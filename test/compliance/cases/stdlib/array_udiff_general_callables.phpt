--TEST--
stdlib array_udiff()/array_diff_ukey() invokable/array/user-string callables (#23551, ext/standard/array.c)
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
    'strcasecmp' => 'strcasecmp',
] as $label => $cb) {
    $r = array_udiff([1, 2, 3], [2], $cb);
    echo "udiff $label: ", json_encode(array_values($r)), "\n";

    $r = array_uintersect([1, 2, 3], [2, 4], $cb);
    echo "uintersect $label: ", json_encode(array_values($r)), "\n";

    $r = array_diff_ukey(['a' => 1, 'b' => 2, 'c' => 3], ['b' => 9], $cb);
    echo "diff_ukey $label: ", json_encode($r), "\n";
}
--EXPECT--
udiff invokable: [1,3]
uintersect invokable: [2]
diff_ukey invokable: {"a":1,"c":3}
udiff array_static: [1,3]
uintersect array_static: [2]
diff_ukey array_static: {"a":1,"c":3}
udiff array_instance: [1,3]
uintersect array_instance: [2]
diff_ukey array_instance: {"a":1,"c":3}
udiff string_fn: [1,3]
uintersect string_fn: [2]
diff_ukey string_fn: {"a":1,"c":3}
udiff closure: [1,3]
uintersect closure: [2]
diff_ukey closure: {"a":1,"c":3}
udiff strcasecmp: [1,3]
uintersect strcasecmp: [2]
diff_ukey strcasecmp: {"a":1,"c":3}
