<?php

declare(strict_types=1);

$h = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
var_export(password_verify('secret', $h));
echo "\n";
var_export(str_starts_with((string) $h, '$2y$'));
echo "\n";
