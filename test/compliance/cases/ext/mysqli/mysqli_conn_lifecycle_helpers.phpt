--TEST--
ext/mysqli mysqli_ping/select_db/change_user/thread_id/kill/get_client_stats (#22174, php-src mysqli_api.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'mysqli_ping',
    'mysqli_select_db',
    'mysqli_change_user',
    'mysqli_thread_id',
    'mysqli_kill',
    'mysqli_get_client_stats',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
foreach (['ping', 'select_db', 'change_user', 'kill', 'thread_id'] as $m) {
    echo 'method_', $m, '=', method_exists('mysqli', $m) ? 'yes' : 'no', "\n";
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
    mysqli_change_user();
    echo "arity_change_user=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_change_user=yes\n";
}
try {
    mysqli_get_client_stats(1);
    echo "arity_client_stats=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_client_stats=yes\n";
}
try {
    mysqli_select_db(false, 'db');
    echo "type_select_db=no\n";
} catch (TypeError $e) {
    echo "type_select_db=yes\n";
}
try {
    mysqli_kill(false, 1);
    echo "type_kill=no\n";
} catch (TypeError $e) {
    echo "type_kill=yes\n";
}
?>
--EXPECT--
mysqli_ping=yes
mysqli_select_db=yes
mysqli_change_user=yes
mysqli_thread_id=yes
mysqli_kill=yes
mysqli_get_client_stats=yes
method_ping=yes
method_select_db=yes
method_change_user=yes
method_kill=yes
method_thread_id=yes
client_stats=array
arity_ping=yes
arity_change_user=yes
arity_client_stats=yes
type_select_db=yes
type_kill=yes
