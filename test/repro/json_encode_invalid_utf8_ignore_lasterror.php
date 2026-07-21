<?php

declare(strict_types=1);

echo json_encode("a\x80b", JSON_INVALID_UTF8_IGNORE), "\n";
echo json_last_error(), "\n";
