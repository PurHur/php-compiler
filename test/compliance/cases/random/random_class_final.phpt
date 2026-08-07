--TEST--
Randomizer + Engine\* ReflectionClass::isFinal() (php-src ext/random/random.stub.php; #28387)
--FILE--
<?php
$classes = [
    Random\Randomizer::class,
    Random\Engine\Mt19937::class,
    Random\Engine\Secure::class,
    Random\Engine\PcgOneseq128XslRr64::class,
    Random\Engine\Xoshiro256StarStar::class,
];
foreach ($classes as $c) {
    echo $c, ' ', (new ReflectionClass($c))->isFinal() ? "final_yes\n" : "final_no\n";
}
?>
--EXPECT--
Random\Randomizer final_yes
Random\Engine\Mt19937 final_yes
Random\Engine\Secure final_yes
Random\Engine\PcgOneseq128XslRr64 final_yes
Random\Engine\Xoshiro256StarStar final_yes
