<?php
// @differential-repeat: 3 implode appendInPlace growth (#36386)
$parts = [];
for ($i = 0; $i < 80; ++$i) {
    $parts[] = (string) ($i % 17);
}
$joined = implode(',', $parts);
        echo strlen($joined), '|', substr($joined, 0, 11), '|', substr($joined, 70, 7), "\n";
echo implode('-', ['a', 'b', 'c']), "\n";
echo implode(['x', 'y']), "\n";
echo implode(',', []), "\n";
echo implode(',', [1, 2, 3]), "\n";
