<?php

declare(strict_types=1);

$wrappers = stream_get_wrappers();
echo $wrappers[0] ?? '?', "\n";
echo json_encode($wrappers), "\n";
