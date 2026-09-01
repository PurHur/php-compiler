<?php

declare(strict_types=1);

function hallo(string $a): void
{
}

function hallo2(string $a): void
{
}

for ($i = 0; $i < 3; ++$i) {
    hallo('hallo');
    hallo2('world');
}

echo "ok\n";
