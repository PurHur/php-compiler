<?php
declare(strict_types=1);

$r = array_replace_recursive(['a' => ['b' => 1]], ['a' => (object) ['c' => 2]]);
echo is_object($r['a']) ? 'R-OBJ' : 'R-ARR';
echo ' ', json_encode($r), "\n";

$m = array_merge_recursive(['a' => ['b' => 1]], ['a' => (object) ['c' => 2]]);
echo is_object($m['a']) ? 'M-OBJ' : 'M-ARR';
echo ' ', json_encode($m), "\n";

try {
    array_replace_recursive((object) ['a' => 1], ['a' => 2]);
    echo "top-uncaught\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type array') ? "top-te\n" : "top-bad\n";
}
