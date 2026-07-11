<?php

declare(strict_types=1);

$encodings = mb_list_encodings();
if (!is_array($encodings) || !in_array('UTF-8', $encodings, true)) {
    exit(1);
}
echo "ok\n";
