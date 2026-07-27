<?php

declare(strict_types=1);

$info = null;
$result = @getimagesize(__FILE__, $info);
echo 'getimagesize result=' . var_export($result, true) . ' info=' . var_export($info, true) . "\n";

$info = null;
$result = @getimagesizefromstring('not-an-image', $info);
echo 'getimagesizefromstring result=' . var_export($result, true) . ' info=' . var_export($info, true) . "\n";
