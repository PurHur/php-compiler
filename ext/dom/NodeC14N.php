<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMNode::C14N() — canonical XML serialization (php-src ext/dom/node.c; #14409).
 *
 * At most 4 user args — Zend ArgumentCountError (#31011; missed by #30616).
 */
final class NodeC14N extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('C14N');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMNode::C14N', 4);
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::C14N()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::C14N() requires VM context in this compiler build');
        }
        [$exclusive, $withComments, $xpath, $nsPrefixes] = self::parseC14NArgs($frame, 1);
        $result = VmDom::c14n(
            $frame->vmContext,
            $node,
            $exclusive,
            $withComments,
            $xpath,
            $nsPrefixes,
            $frame,
            'DOMNode::C14N'
        );
        if (null !== $frame->returnVar) {
            if (false === $result) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->string($result);
            }
        }
    }

    /**
     * @return array{0: bool, 1: bool, 2: ?array, 3: ?array}
     */
    public static function parseC14NArgs(Frame $frame, int $startIndex = 1, string $label = 'DOMNode::C14N()'): array
    {
        $exclusive = false;
        if (isset($frame->calledArgs[$startIndex])) {
            $exclusive = self::boolArg($frame->calledArgs[$startIndex], $label, $startIndex - 1);
        }
        $withComments = false;
        if (isset($frame->calledArgs[$startIndex + 1])) {
            $withComments = self::boolArg($frame->calledArgs[$startIndex + 1], $label, $startIndex);
        }
        $xpath = null;
        if (isset($frame->calledArgs[$startIndex + 2])) {
            $xpath = self::nullableArrayArg($frame->calledArgs[$startIndex + 2], $label, $startIndex + 1);
        }
        $nsPrefixes = null;
        if (isset($frame->calledArgs[$startIndex + 3])) {
            $nsPrefixes = self::nullableArrayArg($frame->calledArgs[$startIndex + 3], $label, $startIndex + 2);
        }

        return [$exclusive, $withComments, $xpath, $nsPrefixes];
    }

    private static function boolArg(Variable $var, string $label, int $index): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type bool, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toBool();
    }

    /**
     * @return ?array<mixed>
     */
    private static function nullableArrayArg(Variable $var, string $label, int $index): ?array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?array, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return self::variableHashToPhpArray($var->toArray());
    }

    /**
     * Convert a VM array/hashtable Variable to a PHP array (#20257).
     *
     * @return array<mixed>
     */
    public static function phpArrayFromVariable(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                'Expected array, %s given',
                VmDom::typeLabel($var)
            ));
        }

        return self::variableHashToPhpArray($var->toArray());
    }

    /**
     * Convert a VM HashTable to a PHP array (string/int keys; nested arrays preserved).
     *
     * @return array<mixed>
     */
    private static function variableHashToPhpArray(\PHPCompiler\VM\HashTable $ht): array
    {
        $out = [];
        foreach ($ht->exportKeyValuePairs(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyVar->type || Variable::TYPE_FLOAT === $keyVar->type) {
                $key = $keyVar->toInt();
            } else {
                $key = $keyVar->toString();
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $valueVar->type) {
                $out[$key] = self::variableHashToPhpArray($valueVar->toArray());
            } elseif (Variable::TYPE_NULL === $valueVar->type) {
                $out[$key] = null;
            } elseif (Variable::TYPE_BOOLEAN === $valueVar->type) {
                $out[$key] = $valueVar->toBool();
            } elseif (Variable::TYPE_INTEGER === $valueVar->type) {
                $out[$key] = $valueVar->toInt();
            } elseif (Variable::TYPE_FLOAT === $valueVar->type) {
                $out[$key] = $valueVar->toFloat();
            } else {
                $out[$key] = $valueVar->toString();
            }
        }

        return $out;
    }
}
