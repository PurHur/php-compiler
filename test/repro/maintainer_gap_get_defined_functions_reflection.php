<?php

declare(strict_types=1);

$p = (new ReflectionFunction('get_defined_functions'))->getParameters()[0];
echo $p->isOptional() ? 'opt' : 'req', ' ',
    $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A', "\n";

$named = get_defined_functions(exclude_disabled: false);
echo is_array($named) ? "named-ok\n" : "named-fail\n";
