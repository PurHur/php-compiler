<?php

declare(strict_types=1);

$rc = new ReflectionClass(DateTime::class);
echo $rc->getExtensionName(), "\n";
echo $rc->getExtension()->getName(), "\n";

$rci = new ReflectionClass(DateTimeImmutable::class);
echo $rci->getExtensionName(), "\n";

$std = new ReflectionClass(stdClass::class);
echo $std->getExtensionName(), "\n";
