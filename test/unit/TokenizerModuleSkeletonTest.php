<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * tokenizer extension module skeleton registration (issue #6940).
 *
 * @group tokenizer_module_skeleton
 */
final class TokenizerModuleSkeletonTest extends TestCase
{
    public function test_tokenizer_module_skeleton_functions_class_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['token_get_all', 'token_name'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertTrue(VmReflection::classExists($ctx, 'PhpToken'), 'PhpToken');
        self::assertArrayHasKey('tokenize', $ctx->classes['phptoken']->methods, 'PhpToken::tokenize');

        $code = <<<'PHP'
<?php
echo (int) function_exists('token_get_all');
echo (int) function_exists('token_name');
echo (int) class_exists('PhpToken');
echo (int) defined('T_ECHO');
echo (int) extension_loaded('tokenizer');
PHP;
        $block = $runtime->parseAndCompile($code, 'tokenizer_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111', ob_get_clean());
    }

    public function test_token_get_all_delegates_to_host_when_available(): void
    {
        if (!\function_exists('token_get_all')) {
            self::markTestSkipped('host ext-tokenizer not loaded');
        }

        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\tokenizer\token_get_all();
        $frame = $fn->getFrame($runtime->vmContext);
        $source = new \PHPCompiler\VM\Variable();
        $source->string('<?php echo 1;');
        $frame->calledArgs = [$source];
        $frame->returnVar = new \PHPCompiler\VM\Variable();

        $fn->execute($frame);

        self::assertSame(\PHPCompiler\VM\Variable::TYPE_ARRAY, $frame->returnVar->type);
        self::assertGreaterThan(0, $frame->returnVar->toArray()->getNumElements());
    }

    public function test_token_name_delegates_to_host_when_available(): void
    {
        if (!\function_exists('token_name') || !\defined('T_ECHO')) {
            self::markTestSkipped('host ext-tokenizer not loaded');
        }

        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\tokenizer\token_name();
        $frame = $fn->getFrame($runtime->vmContext);
        $type = new \PHPCompiler\VM\Variable();
        $type->int(T_ECHO);
        $frame->calledArgs = [$type];
        $frame->returnVar = new \PHPCompiler\VM\Variable();

        $fn->execute($frame);

        self::assertSame('T_ECHO', $frame->returnVar->toString());
    }

    /** Issue #6077 — PhpToken::{tokenize,is,getTokenName,isIgnorable} OOP API. */
    public function test_phptoken_oop_api_issue_6077(): void
    {
        if (!\defined('T_ECHO')) {
            self::markTestSkipped('T_ECHO not defined');
        }

        $code = <<<'PHP'
<?php
$tokens = PhpToken::tokenize('<?php echo 1;');
echo (int) class_exists('PhpToken');
echo (int) method_exists('PhpToken', 'tokenize');
echo $tokens[1]->id;
echo $tokens[1]->getTokenName();
echo (int) $tokens[1]->is(T_ECHO);
echo (int) $tokens[0]->isIgnorable();
echo $tokens[1]->__toString();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'phptoken_6077.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11291T_ECHO11echo', ob_get_clean());
    }
}
