<?php

declare(strict_types=1);

/**
 * Issue #18816 — static property `new Node` without `()` on PHP 8.4 forward profile.
 */

class Node {
    public function __construct(public string $label = 'nil') {}
}

class ListHead {
    public static Node $nil = new Node;
}

echo ListHead::$nil->label, "\n";
echo ListHead::$nil === ListHead::$nil ? "1\n" : "0\n";
