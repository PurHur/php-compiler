<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\Variable;

/** Attribute::__construct(int $flags = Attribute::TARGET_ALL) — VM (#5142, #5937). */
final class AttributeConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Attribute::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Attribute::__construct() called without $this');
        }
        // php-src: Zend/zend_attributes.stub.php — __construct(int $flags = …); ZEND_PARSE_PARAMETERS(0, 1) (#31089)
        $this->requireAtMostUserArgCount($frame, 'Attribute::__construct', 1);

        $flags = AttributeSupport::targetAll();
        if (isset($frame->calledArgs[1])) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $arg->type) {
                throw new \TypeError(
                    'Attribute::__construct(): Argument #1 ($flags) must be of type int, string given'
                );
            }
            $flags = $arg->toInt();
            $allowed = AttributeSupport::targetAll() | AttributeSupport::isRepeatableFlag();
            if (0 !== ($flags & ~$allowed)) {
                throw new \Error('Invalid attribute flags specified');
            }
        }

        $receiver->toObject()->getProperty('flags')->int($flags);
    }
}
