<?php
declare(strict_types=1);
$limit = -2;
$parts = explode('-', 'a-b-c-d', $limit);
echo count($parts), ':', $parts[0], '|', $parts[1], "\n";
