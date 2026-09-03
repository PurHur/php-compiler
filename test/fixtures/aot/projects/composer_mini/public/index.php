<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

echo (new Pkg\Hello())->greet('world'), '|', LegacyGreeter::say(), '|', Pkg\stamp(), "\n";
