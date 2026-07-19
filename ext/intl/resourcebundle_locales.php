<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * resourcebundle_locales() — procedural alias of ResourceBundle::getLocales()
 * (php-src resourcebundle_class.c / resourcebundle.stub.php; #20814).
 */
final class resourcebundle_locales extends Internal
{
    public function __construct()
    {
        parent::__construct('resourcebundle_locales');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'resourcebundle_locales() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $bundle = VmResourceBundle::coerceBundleNameArg($frame->calledArgs[0], 'resourcebundle_locales', 0);
        $locales = VmResourceBundle::getLocales($bundle);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $locales) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($locales as $locale) {
            $v = new Variable();
            $v->string($locale);
            $ht->append($v);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('resourcebundle_locales() is not implemented for JIT in this compiler build (issue #20814)');
    }
}
