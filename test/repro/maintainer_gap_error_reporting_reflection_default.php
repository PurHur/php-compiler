<?php

declare(strict_types=1);

$p = (new ReflectionFunction('error_reporting'))->getParameters()[0];
echo $p->getName(), ' ',
    ($p->isOptional() ? 'opt' : 'req'), ' ',
    ($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A'), "\n";

echo is_int(error_reporting()) ? "call-ok\n" : "call-fail\n";
