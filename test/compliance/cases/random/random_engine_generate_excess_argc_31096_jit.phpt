--TEST--
JIT: Random\Engine generate() excess argc ArgumentCountError (#31096)
--FILE--
<?php
$engines = [
    'Secure' => new Random\Engine\Secure(),
    'Mt19937' => new Random\Engine\Mt19937(1),
    'Xoshiro256StarStar' => new Random\Engine\Xoshiro256StarStar(42),
    'PcgOneseq128XslRr64' => new Random\Engine\PcgOneseq128XslRr64(42),
];
foreach ($engines as $name => $e) {
    try {
        $e->generate(8);
        echo "$name+1 ACCEPTED\n";
    } catch (ArgumentCountError $ex) {
        echo $name, '+1 ', $ex->getMessage(), "\n";
    }
}
echo 'Secure_ok=', strlen((new Random\Engine\Secure())->generate()), "\n";
echo 'Mt19937_ok=', strlen((new Random\Engine\Mt19937(1))->generate()), "\n";
echo 'Xoshiro_ok=', strlen((new Random\Engine\Xoshiro256StarStar(42))->generate()), "\n";
echo 'Pcg_ok=', strlen((new Random\Engine\PcgOneseq128XslRr64(42))->generate()), "\n";
?>
--EXPECT--
Secure+1 Random\Engine\Secure::generate() expects exactly 0 arguments, 1 given
Mt19937+1 Random\Engine\Mt19937::generate() expects exactly 0 arguments, 1 given
Xoshiro256StarStar+1 Random\Engine\Xoshiro256StarStar::generate() expects exactly 0 arguments, 1 given
PcgOneseq128XslRr64+1 Random\Engine\PcgOneseq128XslRr64::generate() expects exactly 0 arguments, 1 given
Secure_ok=8
Mt19937_ok=4
Xoshiro_ok=8
Pcg_ok=8
