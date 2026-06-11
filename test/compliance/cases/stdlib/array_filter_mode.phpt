--TEST--
stdlib array_filter() ARRAY_FILTER_USE_BOTH and USE_KEY (#4243)
--FILE--
<?php
$a = ['keep' => 1, 'drop' => 2];
$out = array_filter(
    $a,
    fn ($v, $k) => $k === 'keep',
    ARRAY_FILTER_USE_BOTH
);
var_export($out);
echo "\n";

$outKey = array_filter(
    $a,
    fn ($k) => $k === 'keep',
    ARRAY_FILTER_USE_KEY
);
var_export($outKey);
echo "\n";

enum E: int { case A = 1; case B = 2; }
$outEnum = array_filter(
    [E::A, E::B],
    function ($v, $k) {
        echo get_debug_type($v), ' ';
        return $k === 0;
    },
    ARRAY_FILTER_USE_BOTH
);
echo "\n";
echo count($outEnum), "\n";
--EXPECT--
array (
  'keep' => 1,
)
array (
  'keep' => 1,
)
E E
1
