<?php
// AOT #26142 — 2-arg usort still works; $direction is not a parameter (named fails at AOT lower)
$ok = [3, 1, 2];
usort($ok, 'strcmp');
echo 'sort=', implode(',', $ok), "\n";
