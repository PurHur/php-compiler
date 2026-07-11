<?php

declare(strict_types=1);

// Zend 8.2: method_exists false; direct call fatals. Re-#15613 / #18092.
echo method_exists(DOMNode::class, 'compareDocumentPosition') ? "fail\n" : "ok\n";
