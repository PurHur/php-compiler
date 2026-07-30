<?php

declare(strict_types=1);

$p = (new ReflectionFunction('debug_backtrace'))->getParameters()[0];
echo $p->getName(), ' ',
    ($p->isOptional() ? 'opt' : 'req'), ' ',
    ($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A'), "\n";

$limit = (new ReflectionFunction('debug_backtrace'))->getParameters()[1];
echo $limit->getName(), ' ',
    ($limit->isOptional() ? 'opt' : 'req'), ' ',
    ($limit->isDefaultValueAvailable() ? var_export($limit->getDefaultValue(), true) : 'N/A'), "\n";

echo is_array(debug_backtrace()) ? "call-ok\n" : "call-fail\n";
