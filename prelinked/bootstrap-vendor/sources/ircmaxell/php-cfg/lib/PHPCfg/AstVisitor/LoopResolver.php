<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\AstVisitor;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\Label;
use PhpParser\NodeVisitorAbstract;

class LoopResolver extends NodeVisitorAbstract
{
    protected static $labelCounter = 0;

    /** @var list<string> */
    protected $continueStack = [];

    /** @var list<bool> true when the matching continue target is a switch (not a loop) */
    protected $continueFromSwitch = [];

    /** @var list<string> */
    protected $breakStack = [];

    public function enterNode(Node $node)
    {
        switch ($node->getType()) {
            case 'Stmt_Break':
                return $this->resolveStack($node, $this->breakStack);
            case 'Stmt_Switch':
                $lbl = $this->makeLabel();
                $this->breakStack[] = $lbl;
                $this->continueStack[] = $lbl;
                $this->continueFromSwitch[] = true;

                break;
            case 'Stmt_Do':
            case 'Stmt_While':
            case 'Stmt_For':
            case 'Stmt_Foreach':
                $this->continueStack[] = $this->makeLabel();
                $this->continueFromSwitch[] = false;
                $this->breakStack[] = $this->makeLabel();

                break;
        }
    }

    public function leaveNode(Node $node)
    {
        switch ($node->getType()) {
            case 'Stmt_Continue':
                $goto = $this->resolveStack($node, $this->continueStack);
                if ($this->isContinueTargetingSwitch($node)) {
                    return [$this->makeContinueSwitchWarning($node), $goto];
                }

                return $goto;
            case 'Stmt_Do':
            case 'Stmt_While':
            case 'Stmt_For':
            case 'Stmt_Foreach':
                $node->stmts[] = new Label(array_pop($this->continueStack));
                array_pop($this->continueFromSwitch);

                return [$node, new Label(array_pop($this->breakStack))];
            case 'Stmt_Switch':
                array_pop($this->continueStack);
                array_pop($this->continueFromSwitch);

                return [$node, new Label(array_pop($this->breakStack))];
        }
    }

    /**
     * @param list<string> $stack
     */
    protected function resolveStack(Node $node, array $stack)
    {
        if ([] === $stack) {
            $keyword = 'Stmt_Break' === $node->getType() ? 'break' : 'continue';

            throw new \LogicException(
                sprintf("'%s' not in the 'loop' or 'switch' context", $keyword)
            );
        }
        if (! $node->num) {
            return new Goto_(end($stack), $node->getAttributes());
        }
        if ($node->num instanceof LNumber) {
            $num = $node->num->value;
            if ($num < 1 || $num > \count($stack)) {
                throw new \LogicException('Too high of a count for '.$node->getType());
            }
            $loc = \array_slice($stack, -$num, 1);

            return new Goto_($loc[0], $node->getAttributes());
        }

        throw new \LogicException('Unimplemented Node Value Type');
    }

    protected function isContinueTargetingSwitch(Node $node): bool
    {
        $level = 1;
        if ($node->num instanceof LNumber) {
            $level = $node->num->value;
        }
        $idx = \count($this->continueFromSwitch) - $level;
        if ($idx < 0 || $idx >= \count($this->continueFromSwitch)) {
            return false;
        }

        return $this->continueFromSwitch[$idx];
    }

    protected function makeContinueSwitchWarning(Node $node): Expression
    {
        $attrs = $node->getAttributes();
        $line = isset($attrs['startLine']) ? (int) $attrs['startLine'] : 0;
        $level = 1;
        if ($node->num instanceof LNumber) {
            $level = $node->num->value;
        }
        $message = $level > 1
            ? \sprintf('"continue %d" targeting switch is equivalent to "break %d"', $level, $level)
            : '"continue" targeting switch is equivalent to "break"';
        $args = [
            new Arg(new String_($message, $attrs)),
        ];
        if ($line > 0) {
            $args[] = new Arg(new LNumber($line, $attrs), false, false, $attrs);
        }

        return new Expression(
            new FuncCall(new Name('compiler_language_warning'), $args, $attrs),
            $attrs
        );
    }

    protected function makeLabel()
    {
        return 'compiled_label_'.mt_rand(0, mt_getrandmax()).'_'.self::$labelCounter++;
    }
}
