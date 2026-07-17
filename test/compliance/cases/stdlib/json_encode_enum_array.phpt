--TEST--
stdlib json_encode() on arrays/maps of backed enum cases matches Zend (#19786)
--FILE--
<?php
enum S: string { case A = 'a'; case B = 'b'; }
enum I: int { case One = 1; case Two = 2; }

echo 'list:', json_encode([S::A, S::B]), "\n";
echo 'map:', json_encode(['x' => S::A, 'y' => S::B]), "\n";
echo 'bare:', json_encode(S::A), "\n";
echo 'int_list:', json_encode([I::One, I::Two]), "\n";
$stored = [S::A, S::B];
echo 'stored:', json_encode($stored), "\n";
--EXPECT--
list:["a","b"]
map:{"x":"a","y":"b"}
bare:"a"
int_list:[1,2]
stored:["a","b"]
