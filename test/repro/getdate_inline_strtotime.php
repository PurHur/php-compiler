<?php

declare(strict_types=1);

echo json_encode(getdate(strtotime('2020-06-21'))['year'] ?? null) . "\n";
