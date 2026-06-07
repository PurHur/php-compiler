<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/** Collator::create() skeleton stub (php-src ext/intl/collator/collator_create.c; #5747). */
final class CollatorCreate extends IntlClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    protected function skeletonIssue(): int
    {
        return 5747;
    }

    protected function declaringClass(): string
    {
        return 'Collator';
    }
}
