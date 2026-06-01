<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_meta_tags() — extract meta name/content pairs from an HTML file (#3703).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_meta_tags.c
 */
final class get_meta_tags extends Internal
{
    public function __construct()
    {
        parent::__construct('get_meta_tags');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('get_meta_tags() expects 1 or 2 arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('get_meta_tags() expects string for argument 1 in this compiler build');
        }
        $useIncludePath = false;
        if ($argc >= 2) {
            $flagVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flagVar->type) {
                throw new \LogicException('get_meta_tags() expects bool for argument 2 in this compiler build');
            }
            $useIncludePath = $flagVar->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmMetaTags::getMetaTags($pathVar->toString(), $useIncludePath);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $key => $value) {
            $slot = new Variable();
            $slot->string((string) $value);
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('get_meta_tags() is VM only in this compiler build');
    }
}
