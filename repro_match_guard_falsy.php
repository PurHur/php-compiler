<?php
echo match (0) {
    1 => 'one',
    true => 'true-arm',
    default => 'def',
}, "\n";

echo match (0) {
    0 => 'zero',
    default => 'def',
}, "\n";
