<?php

declare(strict_types=1);

echo preg_match('/\0/', "a\0b") ? '1' : '0', "\n";
