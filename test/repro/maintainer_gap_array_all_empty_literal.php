<?php

declare(strict_types=1);

// Zend: vacuous truth on empty haystack — inline [] must not trip array guard (#10827).
echo 'array_all([]): ', var_export(array_all([], fn ($v) => (bool) $v), true), "\n";
echo 'array_any([]): ', var_export(array_any([], fn ($v) => (bool) $v), true), "\n";
echo 'array_find([]): ', var_export(array_find([], fn ($v) => (bool) $v), true), "\n";
