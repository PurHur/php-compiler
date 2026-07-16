--TEST--
stdlib stream_context_set_params notification Closure + options bag (#19696)
--FILE--
<?php
declare(strict_types=1);

$c = stream_context_create();
$cb = function () {};
var_export(stream_context_set_params($c, [
    'notification' => $cb,
]));
echo "\n";
$p = stream_context_get_params($c);
echo array_key_exists('notification', $p) ? 'notif_yes' : 'notif_no';
echo "\n";
echo ($p['notification'] === $cb) ? 'same' : 'diff';
echo "\n";

$c2 = stream_context_create();
stream_context_set_params($c2, [
    'options' => ['http' => ['method' => 'POST']],
]);
var_export(stream_context_get_options($c2));
echo "\n";
--EXPECT--
true
notif_yes
same
array (
  'http' => array (
    'method' => 'POST',
  ),
)
