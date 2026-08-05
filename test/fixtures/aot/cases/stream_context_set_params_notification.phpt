--TEST--
AOT: stream_context_set_params notification Closure retained (#27573, re-#8063)
--FILE--
<?php
declare(strict_types=1);
$c = stream_context_create();
$ok = stream_context_set_params($c, ['notification' => function (): void {}]);
echo $ok ? '1' : '0', "\n";
$p = stream_context_get_params($c);
echo array_key_exists('notification', $p) ? '1' : '0', "\n";
--EXPECT--
1
1
