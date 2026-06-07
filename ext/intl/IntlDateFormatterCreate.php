<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/** IntlDateFormatter::create() skeleton stub (php-src ext/intl/dateformat/dateformat_create.c; #5201). */
final class IntlDateFormatterCreate extends IntlClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    protected function skeletonIssue(): int
    {
        return 5201;
    }

    protected function declaringClass(): string
    {
        return 'IntlDateFormatter';
    }
}
