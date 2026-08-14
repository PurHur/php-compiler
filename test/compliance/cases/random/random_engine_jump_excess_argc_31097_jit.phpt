--TEST--
JIT: Random\Engine Xoshiro256StarStar/PcgOneseq128XslRr64 jump() excess argc ArgumentCountError (#31097)
--FILE--
<?php
$x = new Random\Engine\Xoshiro256StarStar(42);
try {
    $x->jump();
    echo "Xoshiro jump() OK\n";
} catch (ArgumentCountError $e) {
    echo 'Xoshiro jump() ', $e->getMessage(), "\n";
}
try {
    $x->jump(1);
    echo "Xoshiro jump(1) ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo 'Xoshiro jump(1) ', $e->getMessage(), "\n";
}
$p = new Random\Engine\PcgOneseq128XslRr64(42);
try {
    $p->jump();
    echo "PCG jump() ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo 'PCG jump() ', $e->getMessage(), "\n";
}
try {
    $p->jump(1);
    echo "PCG jump(1) OK\n";
} catch (ArgumentCountError $e) {
    echo 'PCG jump(1) ', $e->getMessage(), "\n";
}
try {
    $p->jump(1, 2);
    echo "PCG jump(1,2) ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo 'PCG jump(1,2) ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Xoshiro jump() OK
Xoshiro jump(1) Random\Engine\Xoshiro256StarStar::jump() expects exactly 0 arguments, 1 given
PCG jump() Random\Engine\PcgOneseq128XslRr64::jump() expects exactly 1 argument, 0 given
PCG jump(1) OK
PCG jump(1,2) Random\Engine\PcgOneseq128XslRr64::jump() expects exactly 1 argument, 2 given
