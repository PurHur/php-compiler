<?php
$seed = str_repeat("\x01", 16);
$e = new Random\Engine\PcgOneseq128XslRr64($seed);
echo bin2hex($e->generate()), "\n";
echo bin2hex($e->generate()), "\n";
$r = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64($seed));
echo "getInt=", $r->getInt(0, 10), "\n";
echo "nextInt=", $r->nextInt(), "\n";
