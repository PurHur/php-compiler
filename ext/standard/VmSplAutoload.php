<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ScriptStack;
use PHPCompiler\VM\Variable;

/**
 * spl_autoload_register() stack and callback invocation (issue #1369).
 *
 * Runner classes avoid Expr_Closure so this unit AOT-lints in the self-host spine (#1056, #1543).
 */
final class VmSplAutoload
{
    private const DEFAULT_FILE_EXTENSIONS = '.inc,.php';

    /** @var ?string spl_autoload_extensions() override (php-src spl_autoload_extensions TLS). */
    private static ?string $fileExtensions = null;

    public static function register(
        Context $ctx,
        ?Variable $callback,
        bool $prepend
    ): bool {
        $runner = null === $callback
            ? new SplAutoloadDefaultRunner()
            : self::bindCallback($ctx, $callback);
        if ($prepend) {
            array_unshift($ctx->splAutoloadCallbacks, $runner);
        } else {
            $ctx->splAutoloadCallbacks[] = $runner;
        }

        return true;
    }

    public static function unregister(Context $ctx, Variable $callbackArg): bool
    {
        if (EnumCaseSupport::isEnumCaseVariable($callbackArg)) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeErrorUnregister());
        }
        $callback = $callbackArg->resolveIndirect();
        if (!SplAutoloadCallbackPolicy::isVmSupportedType($callback->type)
            && Variable::TYPE_NULL !== $callback->type
        ) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeErrorUnregister());
        }
        foreach ($ctx->splAutoloadCallbacks as $index => $runner) {
            if ($runner->matches($ctx, $callback)) {
                array_splice($ctx->splAutoloadCallbacks, $index, 1);

                return true;
            }
        }

        return false;
    }

    public static function runStack(Context $ctx, string $className): bool
    {
        $lc = strtolower($className);
        if (isset($ctx->classes[$lc])) {
            return true;
        }
        foreach ($ctx->splAutoloadCallbacks as $runner) {
            $runner->run($ctx, $className);
            if (isset($ctx->classes[$lc])) {
                return true;
            }
        }

        return false;
    }

    public static function callbackSnapshot(Context $ctx): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        foreach ($ctx->splAutoloadCallbacks as $runner) {
            $key = new Variable();
            $key->int($index++);
            array_map::appendKeyedCopy($ht, $key, $runner->materializeCallback());
        }

        return $ht;
    }

    public static function fileExtensions(): string
    {
        return self::$fileExtensions ?? self::DEFAULT_FILE_EXTENSIONS;
    }

    public static function setFileExtensions(?string $extensions): void
    {
        self::$fileExtensions = $extensions;
    }

    /**
     * spl_autoload() — default include_path class loader (ext/spl/php_spl.c, #4256).
     */
    public static function defaultAutoload(Context $ctx, string $className, ?string $fileExts = null): void
    {
        $lc = strtolower($className);
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $extList = null !== $fileExts && '' !== $fileExts ? $fileExts : self::fileExtensions();
        $base = str_replace('\\', '/', $lc);
        foreach (explode(',', $extList) as $ext) {
            $ext = trim($ext);
            if ('' === $ext) {
                continue;
            }
            if (!str_starts_with($ext, '.')) {
                $ext = '.'.$ext;
            }
            $resolved = self::resolveAutoloadFile($base.$ext);
            if (false === $resolved) {
                continue;
            }
            $ctx->runtime->vm->executeCompileUnit($resolved);
            if (isset($ctx->classes[$lc])) {
                return;
            }
        }
    }

  /** @return string|false */
    private static function resolveAutoloadFile(string $relative): string|false
    {
        if ('' === $relative || str_contains($relative, "\0")) {
            return false;
        }
        $candidate = ScriptStack::normalize($relative);
        if ('' !== $candidate && VmStatPath::isFile($candidate)) {
            return $candidate;
        }

        return VmFs::resolveIncludePath($relative);
    }

    private static function bindCallback(Context $ctx, Variable $callback): SplAutoloadRunner
    {
        if (EnumCaseSupport::isEnumCaseVariable($callback)) {
            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
        }
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_STRING === $callback->type) {
            $name = $callback->toString();
            if (str_contains($name, '::')) {
                return self::bindStaticName($ctx, $name);
            }

            return self::bindFunction($ctx, $name);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            if (VmClosureCall::isClosure($callback)) {
                return new SplAutoloadClosureRunner(VmClosureCall::resolve($callback), $callback);
            }

            throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::bindArrayCallable($ctx, $callback);
        }

        throw new \TypeError(SplAutoloadCallbackPolicy::invalidCallbackTypeError());
    }

    private static function bindFunction(Context $ctx, string $name): SplAutoloadRunner
    {
        if ('spl_autoload' === $name) {
            return new SplAutoloadDefaultRunner();
        }
        $func = VmUserCall::resolveStringCallback($ctx, $name);

        return new SplAutoloadFunctionRunner($func, $name);
    }

    private static function bindStaticName(Context $ctx, string $callable): SplAutoloadRunner
    {
        [$className, $methodName] = explode('::', $callable, 2);
        $func = self::resolveStaticMethod($ctx, $className, $methodName);

        return new SplAutoloadFunctionRunner($func, $callable);
    }

    private static function bindArrayCallable(Context $ctx, Variable $callable): SplAutoloadRunner
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException('spl_autoload_register() array callback must have two elements');
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodName->type) {
            throw new \LogicException('spl_autoload_register() method name must be a string');
        }
        $method = $methodName->toString();
        if (Variable::TYPE_STRING === $target->type) {
            $func = self::resolveStaticMethod($ctx, $target->toString(), $method);

            return new SplAutoloadFunctionRunner($func, $target->toString().'::'.$method);
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            $class = $target->toObject()->class;
            $methodLc = strtolower($method);
            if (!isset($class->methods[$methodLc])) {
                throw new \LogicException("spl_autoload_register() undefined method {$class->name}::{$method}()");
            }
            $func = $class->methods[$methodLc];

            return new SplAutoloadInstanceMethodRunner(
                $func,
                $target->toObject(),
                [$class->name, $method]
            );
        }
        throw new \LogicException(
            'spl_autoload_register() array callback first element must be a class name string or object'
        );
    }

    private static function resolveStaticMethod(Context $ctx, string $className, string $methodName): PhpFunc
    {
        $lcClass = strtolower($className);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \LogicException("spl_autoload_register() unknown class {$className}");
        }
        $methodLc = strtolower($methodName);
        $class = $ctx->classes[$lcClass];
        if (!isset($class->methods[$methodLc])) {
            throw new \LogicException("spl_autoload_register() undefined static method {$className}::{$methodName}()");
        }
        $func = $class->methods[$methodLc];
        if (!$func instanceof PhpFunc) {
            throw new \LogicException("spl_autoload_register() {$className}::{$methodName} must be a user method");
        }

        return $func;
    }
}

interface SplAutoloadRunner
{
    public function run(Context $ctx, string $className): void;

    public function materializeCallback(): Variable;

    public function matches(Context $ctx, Variable $callback): bool;
}

final class SplAutoloadDefaultRunner implements SplAutoloadRunner
{
    public function run(Context $ctx, string $className): void
    {
        VmSplAutoload::defaultAutoload($ctx, $className);
    }

    public function materializeCallback(): Variable
    {
        $value = new Variable();
        $value->string('spl_autoload');

        return $value;
    }

    public function matches(Context $ctx, Variable $callback): bool
    {
        if (Variable::TYPE_NULL === $callback->type) {
            return true;
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return 'spl_autoload' === $callback->toString();
        }

        return false;
    }
}

final class SplAutoloadFunctionRunner implements SplAutoloadRunner
{
    public function __construct(
        private PhpFunc $func,
        private string $label,
    ) {
    }

    public function run(Context $ctx, string $className): void
    {
        $arg = new Variable();
        $arg->string($className);
        $ctx->runtime->vm->invokePhpFunction($this->func, $arg);
    }

    public function materializeCallback(): Variable
    {
        $value = new Variable();
        $value->string($this->label);

        return $value;
    }

    public function matches(Context $ctx, Variable $callback): bool
    {
        if (Variable::TYPE_STRING === $callback->type) {
            return $callback->toString() === $this->label;
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            $label = self::arrayCallableLabel($callback);

            return null !== $label && $label === $this->label;
        }

        return false;
    }

    /** @return ?string */
    private static function arrayCallableLabel(Variable $callable): ?string
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return null;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodName->type || Variable::TYPE_STRING !== $target->type) {
            return null;
        }

        return $target->toString().'::'.$methodName->toString();
    }
}

final class SplAutoloadClosureRunner implements SplAutoloadRunner
{
    public function __construct(
        private ClosureState $closure,
        private Variable $callback,
    ) {
    }

    public function run(Context $ctx, string $className): void
    {
        $arg = new Variable();
        $arg->string($className);
        VmClosureCall::invokeOne($ctx, $this->closure, $arg);
    }

    public function materializeCallback(): Variable
    {
        $value = new Variable();
        $value->copyFrom($this->callback->resolveIndirect());

        return $value;
    }

    public function matches(Context $ctx, Variable $callback): bool
    {
        if (!VmClosureCall::isClosure($callback)) {
            return false;
        }

        return VmClosureCall::resolve($callback) === $this->closure;
    }
}

final class SplAutoloadInstanceMethodRunner implements SplAutoloadRunner
{
    /**
     * @param array{0: string, 1: string} $label
     */
    public function __construct(
        private PhpFunc $func,
        private ObjectEntry $receiver,
        private array $label,
    ) {
    }

    public function run(Context $ctx, string $className): void
    {
        $recv = new Variable();
        $recv->object($this->receiver);
        $arg = new Variable();
        $arg->string($className);
        $ctx->runtime->vm->invokePhpFunction($this->func, $recv, $arg);
    }

    public function materializeCallback(): Variable
    {
        $elem0 = new Variable();
        $elem0->string($this->label[0]);
        $elem1 = new Variable();
        $elem1->string($this->label[1]);
        $inner = new HashTable();
        $k0 = new Variable();
        $k0->int(0);
        $k1 = new Variable();
        $k1->int(1);
        array_map::appendKeyedCopy($inner, $k0, $elem0);
        array_map::appendKeyedCopy($inner, $k1, $elem1);
        $value = new Variable();
        $value->array($inner);

        return $value;
    }

    public function matches(Context $ctx, Variable $callback): bool
    {
        if (Variable::TYPE_ARRAY !== $callback->type) {
            return false;
        }
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return false;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type || Variable::TYPE_STRING !== $methodName->type) {
            return false;
        }

        return $target->toObject() === $this->receiver
            && $methodName->toString() === $this->label[1];
    }
}
