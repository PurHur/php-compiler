<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Runtime;
use PHPCompiler\Web\Superglobals;

class Context {
    public array $functions = [];
    public array $classes = [];
    private ?RunStackEntry $runStack = null;
    public array $constants = [];

    /** @var array<string, Variable> */
    private array $superglobalVars = [];

    public Runtime $runtime;

    public ErrorReporter $errors;

    public function __construct(Runtime $runtime) {
        $this->runtime = $runtime;
        $this->errors = new ErrorReporter();
    }

    public function constantFetch(string $name): ?Variable {
        switch (strtolower($name)) {
            case 'null':
                return new Variable(Variable::TYPE_NULL);
            case 'false':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(false);
                return $var;
            case 'true':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(true);
                return $var;
            case 'inf':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(INF);
                return $var;
            case 'nan':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(NAN);
                return $var;
            case 'password_bcrypt':
            case 'password_default':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::PASSWORD_DEFAULT);
                return $var;
            case 'filter_validate_int':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::FILTER_VALIDATE_INT);
                return $var;
            case 'filter_validate_email':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::FILTER_VALIDATE_EMAIL);
                return $var;
            case 'input_get':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::INPUT_GET);
                return $var;
            case 'input_post':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::INPUT_POST);
                return $var;
        }
        if (isset($this->constants[$name])) {
            return $this->constants[$name];
        }
        return null;
    }

    public function declareFunction(Func $func): void {
        $lcname = strtolower($func->getName());
        $this->functions[$lcname] = $func;
    }

    public function ensureSuperglobal(string $name): Variable
    {
        if (!Superglobals::isSuperglobalName($name)) {
            throw new \InvalidArgumentException("Unknown superglobal: {$name}");
        }
        if (!isset($this->superglobalVars[$name])) {
            $var = new Variable(Variable::TYPE_ARRAY);
            $var->array(new HashTable());
            $this->superglobalVars[$name] = $var;
        }

        return $this->superglobalVars[$name];
    }

    public function getSuperglobal(string $name): ?Variable
    {
        return $this->superglobalVars[$name] ?? null;
    }

    public function save(Frame $frame): RunStackEntry {
        $this->push($frame);
        $return = $this->runStack;
        $this->runStack = null;
        return $return;
    }

    public function restore(RunStackEntry $runStack): Frame {
        assert(is_null($this->runStack));
        $this->runStack = $runStack->prev;
        return $runStack->frame;
    }

    public function push(Frame $frame): void {
        if (is_null($this->runStack)) {
            $this->runStack = new RunStackEntry($frame);
        } else {
            $this->runStack = $this->runStack->prev = new RunStackEntry($frame);
        }
    }

    public function pop(): ?Frame {
        $return = $this->runStack;
        if (!is_null($this->runStack)) {
            $this->runStack = $this->runStack->prev;
            return $return->frame;
        }
        return null;;
    }
}

class RunStackEntry {
    public ?RunStackEntry $prev = null; 
    public Frame $frame;

    public function __construct(Frame $frame) {
        $this->frame = $frame;
    }
}
