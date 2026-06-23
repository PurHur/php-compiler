<?php

declare(strict_types=1);

echo var_export(sscanf('', '%d'), true), "\n";
echo var_export(sscanf('', '%s'), true), "\n";
echo var_export(sscanf('', '%f'), true), "\n";
echo var_export(sscanf('abc', '%d'), true), "\n";
