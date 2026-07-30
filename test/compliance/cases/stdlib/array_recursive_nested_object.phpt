--TEST--
stdlib array_replace_recursive()/array_merge_recursive() — nested object as HT (#25098, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

// Inline nested (object) must not misbind cast to arg #0 (#25098 / #15858).
$r = array_replace_recursive(['a' => ['b' => 1]], ['a' => (object) ['c' => 2]]);
echo is_object($r['a']) ? 'R-OBJ' : 'R-ARR';
echo ' ';
echo json_encode($r), "\n";

$m = array_merge_recursive(['a' => ['b' => 1]], ['a' => (object) ['c' => 2]]);
echo is_object($m['a']) ? 'M-OBJ' : 'M-ARR';
echo ' ';
echo json_encode($m), "\n";

// Variable path — merge converts object props into the nested array.
$base = ['a' => ['b' => 1]];
$overlay = ['a' => (object) ['c' => 2]];
$mv = array_merge_recursive($base, $overlay);
echo is_object($mv['a']) ? 'Mv-OBJ' : 'Mv-ARR';
echo ' ';
echo json_encode($mv), "\n";

$rv = array_replace_recursive($base, $overlay);
echo is_object($rv['a']) ? 'Rv-OBJ' : 'Rv-ARR';
echo ' ';
echo json_encode($rv), "\n";

// Top-level object arg still TypeErrors like Zend.
try {
    array_replace_recursive((object) ['a' => 1], ['a' => 2]);
    echo "top-uncaught\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type array') ? "top-te\n" : "top-bad\n";
}
?>
--EXPECT--
R-OBJ {"a":{"c":2}}
M-ARR {"a":{"b":1,"c":2}}
Mv-ARR {"a":{"b":1,"c":2}}
Rv-OBJ {"a":{"c":2}}
top-te
