<?php

declare(strict_types=1);

echo preg_replace(['/a/'], ['A'], 'aba'), "\n";
echo preg_replace(['/a/', '/b/'], ['A', 'B'], 'aba'), "\n";
