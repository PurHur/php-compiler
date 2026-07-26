<?php
/**
 * Repro #22174 — mysqli connection lifecycle helpers missing.
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c / mysqli_nonapi.c
 */
declare(strict_types=1);

foreach ([
    'mysqli_connect',
    'mysqli_ping',
    'mysqli_select_db',
    'mysqli_change_user',
    'mysqli_thread_id',
    'mysqli_kill',
    'mysqli_get_client_stats',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}

foreach (['ping', 'select_db', 'change_user', 'kill', 'thread_id'] as $m) {
    echo 'mysqli::', $m, '=', method_exists('mysqli', $m) ? 'yes' : 'NO', "\n";
}

$stats = mysqli_get_client_stats();
echo 'client_stats=', is_array($stats) ? 'array' : gettype($stats), "\n";

try {
    mysqli_ping();
    echo "arity_ping=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_ping=yes\n";
}
try {
    mysqli_select_db(false, 'x');
    echo "type_select_db=no\n";
} catch (TypeError $e) {
    echo "type_select_db=yes\n";
}
try {
    mysqli_kill(false, 1);
    echo "type_kill_link=no\n";
} catch (TypeError $e) {
    echo "type_kill_link=yes\n";
}
