<?php

declare(strict_types=1);

/**
 * Maintainer repro: class constant `new` expression (#12940, Zend/zend_compile.c).
 *
 * Zend 8.3+: compiles and evaluates at class-load time.
 * Reference profile: compile error via NewWithoutParensCompileCheck.
 */

class Holder {
    public const DT = new DateTime('2020-01-01');
}

echo Holder::DT->format('Y'), "\n";
echo Holder::DT === Holder::DT ? "1\n" : "0\n";
