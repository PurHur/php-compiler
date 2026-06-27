<?php

declare(strict_types=1);

echo preg_replace_callback('/a/', fn ($m) => 'b', 'a'), "\n";
