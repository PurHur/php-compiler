<?php

declare(strict_types=1);

$s = 'a,b,c';
echo strtok($s, ','), '|';
echo strtok(null, ',') === false ? '' : 'bad', "\n";
echo strtok($s, ','), strtok(','), strtok(','), (strtok(',') === false ? 'end' : 'bad');
