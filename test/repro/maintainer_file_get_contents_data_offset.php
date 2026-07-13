<?php

declare(strict_types=1);

$payload = '0123456789';
echo file_get_contents('data://text/plain,'.$payload, false, null, 3, 4), "\n";
