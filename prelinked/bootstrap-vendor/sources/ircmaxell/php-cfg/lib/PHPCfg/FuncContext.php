<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg;

/**
 * Stores per-function compiler state.
 */
class FuncContext
{
    /** @var Block[] */
    public $labels = [];

    /** @var \SplObjectStorage */
    public $scope;

    /** @var \SplObjectStorage */
    public $incompletePhis;

    /** @var bool */
    public $complete = false;

    /** @var array[] */
    public $unresolvedGotos = [];

    /**
     * Nesting scopes used to validate `goto` jumps (Zend parity).
     *
     * Loop/switch: jumps into a loop/switch are disallowed.
     * Finally: jumps into/out of a finally block are disallowed.
     *
     * @var int[]
     */
    public $gotoLoopSwitchStack = [];

    /** @var int[] */
    public $gotoFinallyStack = [];

    /** @var int */
    public $gotoScopeId = 0;

    /**
     * @var array<string, array{loopSwitch: int[], finally: int[]}>
     */
    public $gotoLabelScopes = [];

    public function __construct()
    {
        $this->scope = new \SplObjectStorage();
        $this->incompletePhis = new \SplObjectStorage();
    }

    public function setValueInScope(Block $block, $name, Operand $value)
    {
        if (! isset($this->scope[$block])) {
            $this->scope[$block] = [];
        }
        // Because PHP.
        $vars = $this->scope[$block];
        $vars[$name] = $value;
        $this->scope[$block] = $vars;
    }

    public function isLocalVariable(Block $block, $name)
    {
        if (! isset($this->scope[$block])) {
            return false;
        }
        $vars = $this->scope[$block];

        return isset($vars[$name]);
    }

    public function addToIncompletePhis(Block $block, $name, Op\Phi $phi)
    {
        if (! isset($this->incompletePhis[$block])) {
            $this->incompletePhis[$block] = [];
        }
        // Because PHP.
        $phis = $this->incompletePhis[$block];
        $phis[$name] = $phi;
        $this->incompletePhis[$block] = $phis;
    }
}
