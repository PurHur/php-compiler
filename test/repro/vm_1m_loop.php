<?php

declare(strict_types=1);

$a = 0;
for ($i = 0; $i < 1000000; ++$i) {
    ++$a;
}
echo "Done $a\n";
