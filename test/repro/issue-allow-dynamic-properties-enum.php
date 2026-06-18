<?php
declare(strict_types=1);

#[\AllowDynamicProperties]
enum Bad: int {
    case X = 1;
}

echo "compiled\n";
