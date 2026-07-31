<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/**
 * Shared ReflectionFunctionAbstract source-location getters (#7358).
 */
abstract class ReflectionSourceGetter extends VmClassMethod
{
    /** @var callable */
    private $apply;

    /**
     * @param callable $apply
     */
    public function __construct(string $methodName, callable $apply)
    {
        parent::__construct($methodName);
        $this->apply = $apply;
    }

    abstract protected function resolveLocation(Frame $frame): ?SourceLocation;

    abstract protected function resolveEntry(Frame $frame): ClassEntry;

    public function execute(Frame $frame): void
    {
        $entry = $this->resolveEntry($frame);
        $location = ($this->resolveLocation($frame) ?? new SourceLocation())->forReflection();
        if (null !== $frame->returnVar) {
            ($this->apply)($location, $entry, $frame);
        }
    }

    protected static function classEntryFromReflection(Frame $frame, int $argIndex): ClassEntry
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[$argIndex]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }

        return $entry;
    }

    protected static function methodEntryFromReflection(Frame $frame, int $argIndex): array
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[$argIndex]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $methodName = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($methodName);

        return [$entry, $methodLc];
    }
}
