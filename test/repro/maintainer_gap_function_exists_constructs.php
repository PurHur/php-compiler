<?php

declare(strict_types=1);

$symbols = ['__halt_compiler', 'eval', 'exit', 'die'];
foreach ($symbols as $sym) {
    echo $sym, '=', function_exists($sym) ? 'true' : 'false', "\n";
}
echo 'strlen=', function_exists('strlen') ? 'true' : 'false', "\n";
