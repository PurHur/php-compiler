<?php
// #27212 — thin AOT array_values must print values (not empty).
echo implode(',', array_values(['a' => 1, 'b' => 2])), "\n";
