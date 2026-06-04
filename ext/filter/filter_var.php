<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\filter_var as StandardFilterVar;

/**
 * filter_var() — ext/filter entry delegating to ext/standard (php-src ext/filter/filter.c; #5839).
 */
final class filter_var extends StandardFilterVar
{
}
