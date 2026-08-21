<?php
declare(strict_types=1);
// Issue #33094 — ArrayObject + #18784 ternary-literal echo with concat.
$o = new ArrayObject(['z' => 0]);
echo (true ? 'true' : 'false') . "\n";
echo (isset($o['z']) ? 'true' : 'false') . "\n";
