<?php

declare(strict_types=1);

echo preg_replace_callback_array(['/a/' => fn ($m) => 'b'], 'ax'), "\n";
