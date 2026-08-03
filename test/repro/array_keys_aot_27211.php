<?php
// #27211 — thin AOT array_keys must print string keys (not empty).
echo implode(',', array_keys(['a' => 1, 'b' => 2])), "\n";
