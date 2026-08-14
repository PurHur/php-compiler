--TEST--
JIT: Random\Randomizer getBytes/getInt/shuffleArray/pickArrayKeys excess argc ArgumentCountError (#31092)
--FILE--
<?php
$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
$cases = [
    'getBytes+1' => fn () => $r->getBytes(1, 1),
    'getInt+1' => fn () => $r->getInt(1, 2, 1),
    'shuffleArray+1' => fn () => $r->shuffleArray([1, 2], 1),
    'pickArrayKeys+1' => fn () => $r->pickArrayKeys([1 => 'a', 2 => 'b'], 1, 1),
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ' ', $e->getMessage(), "\n";
    }
}
echo 'getBytes_ok=', strlen($r->getBytes(1)), "\n";
echo 'getInt_ok=', $r->getInt(1, 1), "\n";
echo 'shuffleArray_ok=', count($r->shuffleArray([1, 2])), "\n";
echo 'pickArrayKeys_ok=', count($r->pickArrayKeys([1 => 'a', 2 => 'b'], 1)), "\n";
?>
--EXPECT--
getBytes+1 Random\Randomizer::getBytes() expects exactly 1 argument, 2 given
getInt+1 Random\Randomizer::getInt() expects exactly 2 arguments, 3 given
shuffleArray+1 Random\Randomizer::shuffleArray() expects exactly 1 argument, 2 given
pickArrayKeys+1 Random\Randomizer::pickArrayKeys() expects exactly 2 arguments, 3 given
getBytes_ok=1
getInt_ok=1
shuffleArray_ok=2
pickArrayKeys_ok=1
