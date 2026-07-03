<?php

declare(strict_types=1);

/**
 * Issue #15367 — typed class constants parse/compile on 8.3+ forward profile.
 *
 * ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/maintainer_gap_typed_class_const_syntax.php'
 */
class Foo
{
    public const string FOO = 'bar';
}

echo Foo::FOO, "\n";
