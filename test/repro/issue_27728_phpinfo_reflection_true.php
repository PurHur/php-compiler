<?php

declare(strict_types=1);

/**
 * phpinfo Reflection return type — Zend 8.4 stub `true` (#27728, re-#24550).
 *
 * php-src: ext/standard/basic_functions.stub.php
 *   function phpinfo(int $flags = INFO_ALL): true
 */
$r = new ReflectionFunction('phpinfo');
echo 'hasReturn='.($r->hasReturnType() ? 'yes' : 'no')."\n";
echo 'type='.($r->hasReturnType() ? (string) $r->getReturnType() : '(none)')."\n";
