<?php
declare(strict_types=1);

$ok = strlen::class === 'strlen'
    && array_map::class === 'array_map';
echo $ok ? "ok\n" : "fail\n";
