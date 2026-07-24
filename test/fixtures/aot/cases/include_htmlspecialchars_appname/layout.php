<?php

declare(strict_types=1);

/** @var string $appName */
echo 'ECHO=', $appName, "\n";
echo 'HTML=', htmlspecialchars($appName), "\n";
echo 'LIT=', htmlspecialchars('MiniWebApp'), "\n";
