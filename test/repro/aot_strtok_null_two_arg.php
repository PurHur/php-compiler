<?php

// Soft-null continuation (no strict_types) — #5515; strict null is #29784.
$s = 'a,b,c';
echo strtok($s, ','), '|';
echo strtok(null, ',') === false ? '' : 'bad', "\n";
echo strtok($s, ','), strtok(','), strtok(','), (strtok(',') === false ? 'end' : 'bad');
