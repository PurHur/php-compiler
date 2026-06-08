<?php
declare(strict_types=1);

echo function_exists('bcpowmod') ? "yes\n" : "no\n";
echo bcpowmod('2', '10', '1000'), "\n";
