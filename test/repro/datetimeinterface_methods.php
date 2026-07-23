<?php
declare(strict_types=1);
$m = get_class_methods('DateTimeInterface') ?: [];
sort($m);
echo implode(',', $m), "\n";
foreach (['format', 'diff', 'getTimestamp', 'getTimezone', 'getOffset', '__serialize', '__unserialize', '__wakeup'] as $name) {
    echo $name, '=', (int) method_exists('DateTimeInterface', $name), "\n";
}
