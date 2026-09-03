<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Extension-owned object[$offset] handlers that are not ArrayAccess (#36204).
 *
 * php-src uses zend_object_handlers.read_dimension / has_dimension for
 * DOMNodeList / DOMNamedNodeMap / Dom\TokenList and ResourceBundle.
 */
final class ObjectDimensionHandler
{
    /** @var callable(ObjectEntry): bool */
    public $matches;

    /** @var callable(ObjectEntry, Variable, Variable): void */
    public $read;

    /** @var null|callable(ObjectEntry, Variable): bool */
    public $has;

    /** When true, write/unset Error with "Cannot use object of type … as array". */
    public bool $rejectWrite;

    /**
     * @param callable(ObjectEntry): bool                    $matches
     * @param callable(ObjectEntry, Variable, Variable): void $read
     * @param null|callable(ObjectEntry, Variable): bool      $has
     */
    public function __construct(
        callable $matches,
        callable $read,
        ?callable $has = null,
        bool $rejectWrite = true
    ) {
        $this->matches = $matches;
        $this->read = $read;
        $this->has = $has;
        $this->rejectWrite = $rejectWrite;
    }
}
