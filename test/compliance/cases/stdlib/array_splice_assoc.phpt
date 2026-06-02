--TEST--
stdlib array_splice() for associative arrays (#3581)
--SKIPIF--
<?php
if (isset($_SERVER['argv'][0]) && str_contains((string) $_SERVER['argv'][0], 'jit.php')) {
    die('skip keyed array_splice pending LLVM assoc lowering (#3581)');
}
?>
--FILE--
<?php
$b = array('a' => 1, 'b' => 2, 'c' => 3);
$r = array_splice($b, 1, 1);
echo count($b), "\n";
echo count($r), "\n";
echo $b['a'], "\n";
echo $b['c'], "\n";
echo $r['b'], "\n";

$d = array('a' => 1, 'b' => 2, 'c' => 3);
array_splice($d, 1, 1, array('x' => 9, 'y' => 10));
echo count($d), "\n";
echo $d['a'], "\n";
echo $d[0], "\n";
echo $d[1], "\n";
echo $d['c'], "\n";
--EXPECT--
2
1
1
3
2
4
1
9
10
3
