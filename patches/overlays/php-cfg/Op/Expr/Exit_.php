<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PhpCfg\Operand;

class Exit_ extends Expr
{
    public $expr = null;

    /** Optional message operand for `exit($status, $message)` (#6718). */
    public $message = null;

    public function __construct(Operand $expr = null, array $attributes = [])
    {
        parent::__construct($attributes);
        if (null !== $expr) {
            $this->expr = $this->addReadRef($expr);
        }
    }

    public static function withMessage(Operand $status, Operand $message, array $attributes = []): self
    {
        $exit = new self($status, $attributes);
        $exit->message = $exit->addReadRef($message);

        return $exit;
    }

    public function getVariableNames(): array
    {
        return ['expr', 'message', 'result'];
    }
}
