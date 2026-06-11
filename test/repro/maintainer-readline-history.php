<?php

declare(strict_types=1);

$functions = [
    'readline_info',
    'readline_add_history',
    'readline_clear_history',
    'readline_list_history',
    'readline_write_history',
    'readline_callback_handler_remove',
];

foreach ($functions as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}

readline_clear_history();
readline_add_history('alpha');
readline_add_history('beta');
$hist = readline_list_history();
echo 'history_count=', count($hist), "\n";
echo 'history0=', $hist[0] ?? '', "\n";
