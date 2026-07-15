<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\phar\VmPhar;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** Phar::running() — VM (#3436, ext/phar/phar_object.c). */
final class PharRunning extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('running');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $retPhar = false;
            if (\count($frame->calledArgs) >= 1) {
                $retPhar = $frame->calledArgs[0]->resolveIndirect()->toBool();
            }
            $scriptPath = self::resolveScriptPath($frame);
            $result = VmPhar::runningPath($scriptPath, $retPhar);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->string($result);
            });
        });
    }

    private static function resolveScriptPath(Frame $frame): string
    {
        if (null !== $frame->vmContext) {
            $server = $frame->vmContext->getSuperglobal('_SERVER');
            if (null !== $server && Variable::TYPE_ARRAY === $server->type) {
                $filename = $server->toArray()->find('SCRIPT_FILENAME');
                if (null !== $filename && Variable::TYPE_STRING === $filename->type && '' !== $filename->toString()) {
                    return $filename->toString();
                }
            }
        }
        if ('' !== $frame->scriptPath) {
            return $frame->scriptPath;
        }

        return '';
    }
}
