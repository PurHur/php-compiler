<?php

declare(strict_types=1);

$rm = new ReflectionMethod(DateTime::class, 'format');
echo $rm->getExtension()?->getName(), "\n";
