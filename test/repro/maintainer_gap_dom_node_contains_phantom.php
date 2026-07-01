<?php

declare(strict_types=1);

// Zend 8.2 reference profile: DOMNode::contains() undefined (#14723).
echo method_exists(DOMNode::class, 'contains') ? "fail\n" : "ok\n";
