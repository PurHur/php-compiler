<?php
// #24143 — AOT echo match(...) with default arm (was segfault after c:main_before_php)
$x = 2;
echo match ($x) {
    1 => 'a',
    2 => 'b',
    default => 'd',
}, "\n";
