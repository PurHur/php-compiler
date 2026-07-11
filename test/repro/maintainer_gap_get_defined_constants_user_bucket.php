<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$user = $c['user'] ?? [];
$userCount = count($user);
$inotify = $c['inotify'] ?? [];
$inotifyCount = count($inotify);
echo 'user_count=', $userCount, "\n";
echo 'inotify_count=', $inotifyCount, "\n";
if ($userCount > 0) {
    $keys = array_keys($user);
    sort($keys);
    echo 'user_sample=', implode(',', array_slice($keys, 0, 5)), "\n";
}
