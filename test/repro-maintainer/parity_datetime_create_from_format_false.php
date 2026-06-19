<?php
declare(strict_types=1);

$dt = DateTimeImmutable::createFromFormat('Y', 'notadate');
var_export($dt);
echo "\n";
var_export(DateTimeImmutable::getLastErrors());
echo "\n";
