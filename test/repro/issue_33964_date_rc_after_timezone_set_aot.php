<?php
// #33964 — date('r')/date('c') after set must use local offset (not UTC-bake).
date_default_timezone_set('Europe/Berlin');
echo date('c', 1721037600), "\n";
echo date('r', 1721037600), "\n";
echo gmdate('c', 1721037600), "\n";
// UTC default path (no set) still OK
date_default_timezone_set('UTC');
echo date('c', 1721037600), "\n";
echo date('r', 1721037600), "\n";
