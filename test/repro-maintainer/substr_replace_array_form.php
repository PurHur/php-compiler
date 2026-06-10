<?php

declare(strict_types=1);

$result = substr_replace(['abcdef', '123'], '.', [2, 1], [2, 1]);
echo json_encode($result), "\n";
