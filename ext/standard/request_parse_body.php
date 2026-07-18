<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\JIT\Builtin\RequestParseBodyRuntime;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value;

/**
 * request_parse_body() — parse request body without populating superglobals (PHP 8.4+).
 *
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20521): {@see JitRequestParseBodyKernel}.
 * Embed / non-thin: NestedJIT {@see RequestParseBodyRuntime}.
 * php-src: ext/standard/http.c
 */
final class request_parse_body extends Internal
{
    public function __construct()
    {
        parent::__construct('request_parse_body');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc && 1 !== $argc) {
            throw new \ArgumentCountError('request_parse_body() expects at most 1 argument, '.$argc.' given');
        }
        $options = null;
        if (1 === $argc) {
            $optVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $optVar->type) {
                if (Variable::TYPE_ARRAY !== $optVar->type) {
                    throw new \TypeError('request_parse_body(): Argument #1 ($options) must be of type ?array');
                }
                $options = VmHttpBuildQuery::export($optVar, $frame);
                if (!is_array($options)) {
                    throw new \TypeError('request_parse_body(): Argument #1 ($options) must be of type ?array');
                }
            }
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($options): void {
            [$post, $files] = RequestParseBodyEngine::parseFromEnvironment($options);
            $postHt = new HashTable();
            $filesHt = new HashTable();
            if (is_array($post)) {
                VmParseStr::mergeInto($postHt, $post);
            }
            if (is_array($files)) {
                VmParseStr::mergeInto($filesHt, $files);
            }
            $pair = new HashTable();
            $postVar = new Variable(Variable::TYPE_ARRAY);
            $postVar->array($postHt);
            $filesVar = new Variable(Variable::TYPE_ARRAY);
            $filesVar->array($filesHt);
            $pair->addIndex(0, $postVar);
            $pair->addIndex(1, $filesVar);
            $ret->array($pair);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc && 1 !== $argc) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                'request_parse_body() expects at most 1 argument, '.$argc.' given'
            );

            return HashTableHelper::alloc($context);
        }
        if (1 === $argc && JITVariable::TYPE_NULL !== $args[0]->type) {
            ExceptionBridge::emitTypeError($context, 'request_parse_body(): Argument #1 ($options) must be of type ?array');

            return HashTableHelper::alloc($context);
        }

        $postHt = HashTableHelper::alloc($context);
        $filesHt = HashTableHelper::alloc($context);
        if ($context->isThinStandaloneAotMain()) {
            JitRequestParseBodyKernel::ensureLinked($context);
            $context->builder->call(
                $context->lookupFunction(JitRequestParseBodyKernel::BRIDGE_NAME),
                $postHt,
                $filesHt
            );
        } else {
            $helperFn = RequestParseBodyRuntime::helperFunction(
                $context,
                RequestParseBodyRuntime::parseIntoNativeHelperLogical($context)
            );
            $optionsNull = $helperFn->getParam(2)->typeOf()->constNull();
            JitNestedHelperCoerce::callHelper(
                $context,
                $helperFn,
                [
                    JitNestedHelperCoerce::ptrToI64($context, $postHt),
                    JitNestedHelperCoerce::ptrToI64($context, $filesHt),
                    $optionsNull,
                ]
            );
        }

        $result = HashTableHelper::alloc($context);
        $postVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $postHt);
        $filesVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $filesHt);
        HashTableHelper::setAtIndex(
            $context,
            $result,
            $context->constantFromInteger(0, 'size_t'),
            $postVar
        );
        HashTableHelper::setAtIndex(
            $context,
            $result,
            $context->constantFromInteger(1, 'size_t'),
            $filesVar
        );

        return $result;
    }
}
