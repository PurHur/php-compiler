<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** DatePeriod::createFromISO8601String() — #7296. */
final class DatePeriodCreateFromISO8601StringTest extends TestCase
{
    public function testCreateFromISO8601StringOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }

        $code = <<<'PHP'
<?php
var_export(method_exists(DatePeriod::class, 'createFromISO8601String'));
echo "\n";
$p = DatePeriod::createFromISO8601String('2024-01-01T00:00:00/2024-01-03T00:00:00/P1D');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
    break;
}
PHP;

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'test.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        self::assertSame("true\n2024-01-01\n", $out);
    }

    public function testCreateFromISO8601StringZuluStartDurationEnd(): void
    {
        if (!CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }

        $code = <<<'PHP'
<?php
$p = DatePeriod::createFromISO8601String('2020-01-01T00:00:00Z/P1D/2020-01-05T00:00:00Z');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
    break;
}
PHP;

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'test.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        self::assertSame("2020-01-01\n", $out);
    }

    /** Issue #19737 — ISO8601 factory options bitmask matches end-date ctor form. */
    public function testCreateFromISO8601StringIncludeEndDateOptions(): void
    {
        $code = <<<'PHP'
<?php
$spec = '2020-01-01/P1D/2020-01-03';
$out = [];
foreach (DatePeriod::createFromISO8601String($spec, DatePeriod::INCLUDE_END_DATE) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo implode(',', $out), "\n";
$out2 = [];
foreach (DatePeriod::createFromISO8601String(
    '2020-01-01/P1D/2020-01-04',
    DatePeriod::EXCLUDE_START_DATE | DatePeriod::INCLUDE_END_DATE
) as $d) {
    $out2[] = $d->format('Y-m-d');
}
echo implode(',', $out2), "\n";
PHP;

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'test.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        self::assertSame("2020-01-01,2020-01-02,2020-01-03\n2020-01-02,2020-01-03,2020-01-04\n", $out);
    }

    /** Issue #27923 — Reflection arity/types/return + named specification/options. */
    public function testCreateFromISO8601StringReflectionAndNamedArgs(): void
    {
        if (!CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            self::markTestSkipped('requires PHP_COMPILER_PROFILE=8.4');
        }

        $code = <<<'PHP'
<?php
$r = new ReflectionMethod(DatePeriod::class, 'createFromISO8601String');
echo 'arity=', $r->getNumberOfParameters(), ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' ', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' opt=', (int) $p->isOptional(), "\n";
}
$p = DatePeriod::createFromISO8601String(specification: 'R1/2024-01-01T00:00:00Z/P1D');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
}
PHP;

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'test.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        self::assertSame(
            "arity=2 ret=static\nspecification string opt=0\noptions int opt=1\n2024-01-01\n2024-01-02\n",
            $out
        );
    }
}
