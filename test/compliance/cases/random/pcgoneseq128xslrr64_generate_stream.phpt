--TEST--
Random\Engine\PcgOneseq128XslRr64::generate() bytes + nextInt sign (#31054)
--FILE--
<?php
$seed = str_repeat("\x01", 16);
$e = new Random\Engine\PcgOneseq128XslRr64($seed);
echo bin2hex($e->generate()), "\n";
echo bin2hex($e->generate()), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo "getInt=", $r->getInt(0, 10), "\n";
echo "nextInt=", $r->nextInt(), "\n";
?>
--EXPECT--
0447ba4bc9010f0d
b09e3c55a84612b3
getInt=0
nextInt=6451726785584189272
