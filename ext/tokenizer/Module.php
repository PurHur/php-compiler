<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * tokenizer extension module entry (php-src ext/tokenizer/tokenizer.c; issue #6940).
 *
 * Native lexer + PhpToken::tokenize() (#3171, #6077, #6794).
 */
class Module extends ModuleAbstract
{
    /**
     * PhpToken thin-AOT public props + method visibility (#27263 / #6794 / #36204).
     *
     * php-src: ext/tokenizer/tokenizer.stub.php — $id/$text/$line/$pos.
     */
    public function jitInit(JIT\Context $context): void
    {
        $context->type->object->registerExternalClassSeeder('phptoken', static function ($obj, int $id): void {
            $obj->defineProperty($id, VmPhpToken::PROP_ID, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmPhpToken::PROP_TEXT, Variable::TYPE_VALUE);
            $obj->defineProperty($id, VmPhpToken::PROP_LINE, Variable::TYPE_NATIVE_LONG);
            $obj->defineProperty($id, VmPhpToken::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $obj->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            $obj->defineMethodVisibility($id, '__construct', $pub);
            $obj->defineMethodVisibility($id, 'tokenize', $pubStatic);
            $obj->defineMethodVisibility($id, 'gettokenname', $pub, 'getTokenName');
            $obj->defineMethodVisibility($id, 'is', $pub);
            $obj->defineMethodVisibility($id, 'isignorable', $pub, 'isIgnorable');
            $obj->defineMethodVisibility($id, '__tostring', $pub, '__toString');
        });
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (TokenConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new token_get_all(),
            new token_name(),
        ];
    }
}
