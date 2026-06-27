<?php

declare(strict_types=1);

$serialized = serialize(-0.0);
echo $serialized, "\n";
echo $serialized === 'd:-0;' ? 'ok' : 'fail', "\n";
