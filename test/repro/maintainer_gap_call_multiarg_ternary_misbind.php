<?php
declare(strict_types=1);

function pair(string $a, int $b): void
{
    echo 'a=', $a, ' b=', $b, "\n";
}

pair(true ? 'yes' : 'no', false ? 1 : 2);
echo 'sprintf=', sprintf('%s-%d', true ? 'yes' : 'no', false ? 1 : 2), "\n";
