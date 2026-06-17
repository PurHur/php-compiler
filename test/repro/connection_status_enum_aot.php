<?php

declare(strict_types=1);

echo connection_status(), "\n";
echo connection_status() === CONNECTION_NORMAL ? "match\n" : "bad\n";
