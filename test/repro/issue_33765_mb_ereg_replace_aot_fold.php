<?php
declare(strict_types=1);
echo mb_ereg_replace('a', 'x', 'aAa'), "\n";
echo mb_ereg_replace('World', 'Earth', 'Hello World'), "\n";
echo mb_ereg_replace('nomatch', 'X', 'abc'), "\n";
