--TEST--
stdlib Random\Randomizer getFloat ClosedOpen/OpenClosed match Zend γ-section (#28526, ext/random/gammasection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(123));
echo $r->getFloat(0.0, 1.0), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(123));
echo $r->getFloat(0.0, 1.0, Random\IntervalBoundary::OpenClosed), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(123));
echo $r->getFloat(0.0, 1.0, Random\IntervalBoundary::OpenOpen), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(123));
echo $r->getFloat(0.0, 1.0, Random\IntervalBoundary::ClosedClosed), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(123));
echo $r->nextFloat(), "\n";
$r = new Random\Randomizer(new Random\Engine\Mt19937(123));
echo $r->getFloat(0.0, 1.0), "\n";
--EXPECT--
0.71550887573542
0.71550887573542
0.7155088757354
0.71550887573543
0.07093969293177
0.86750140358487
