<?php
declare(strict_types=1);
echo mb_eregi_replace('A', 'x', 'aAa'), "\n";
echo mb_eregi_replace('WORLD', 'Earth', 'Hello World'), "\n";
echo mb_eregi_replace('nomatch', 'X', 'abc'), "\n";
