<?php
/**
 * PROFILE=8.4: set-only property hook must be backed (RFC), not virtual.
 * Zend stores the transformed value; VM throws "Must not write to virtual property".
 */
error_reporting(E_ALL);

class SetOnly {
    public string $name {
        set => strtoupper($value);
    }

    public function __construct(string $name) {
        $this->name = $name;
    }
}

$o = new SetOnly('ada');
echo 'get=', $o->name, "\n";
$o->name = 'bob';
echo 'after=', $o->name, "\n";
