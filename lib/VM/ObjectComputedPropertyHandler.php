<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Extension-owned computed object properties (DOM / XMLReader / SimpleXML, …) (#36204).
 *
 * php-src uses zend_object_handlers.read_property / has_property / write_property.
 * lib/ must not import PHPCompiler\ext\*; Module::init registers implementations.
 */
final class ObjectComputedPropertyHandler
{
    /** @var callable(ObjectEntry, string): bool */
    public $isManaged;

    /** @var null|callable(ObjectEntry, string): Variable */
    public $get;

    /** @var null|callable(ObjectEntry, string): ?bool null = not handled */
    public $isset;

    /** @var null|callable(ObjectEntry, string): ?bool null = not handled */
    public $empty;

    /** @var null|callable(ObjectEntry, string): void may throw \Error */
    public $rejectWrite;

    /** @var null|callable(ObjectEntry, string, Variable, Context): bool */
    public $tryAssign;

    /**
     * @param callable(ObjectEntry, string): bool                         $isManaged
     * @param null|callable(ObjectEntry, string): Variable                $get
     * @param null|callable(ObjectEntry, string): ?bool                   $isset
     * @param null|callable(ObjectEntry, string): ?bool                   $empty
     * @param null|callable(ObjectEntry, string): void                    $rejectWrite
     * @param null|callable(ObjectEntry, string, Variable, Context): bool $tryAssign
     */
    public function __construct(
        callable $isManaged,
        ?callable $get = null,
        ?callable $isset = null,
        ?callable $empty = null,
        ?callable $rejectWrite = null,
        ?callable $tryAssign = null
    ) {
        $this->isManaged = $isManaged;
        $this->get = $get;
        $this->isset = $isset;
        $this->empty = $empty;
        $this->rejectWrite = $rejectWrite;
        $this->tryAssign = $tryAssign;
    }
}
