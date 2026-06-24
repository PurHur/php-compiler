<?php

$s = gc_status();
ksort($s);
echo implode(',', array_keys($s)), "\n";
echo 'running=', array_key_exists('running', $s) ? 'yes' : 'no', "\n";
echo 'runs=', array_key_exists('runs', $s) ? 'yes' : 'no', "\n";
