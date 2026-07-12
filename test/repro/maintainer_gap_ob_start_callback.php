<?php

declare(strict_types=1);

ob_start(static fn (string $b, int $p): string => strtoupper($b));
echo 'hi';
echo ob_get_clean();
