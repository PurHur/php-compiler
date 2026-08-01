<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\ private / private-final __construct — user `new` is rejected by visibility (#26059).
 *
 * php-src: ext/dom/php_dom.stub.php — Dom\Node `private final function __construct()`;
 * Dom\TokenList / Dom\NamespaceInfo `private function __construct()`.
 * Factories allocate via ObjectEntry and never invoke this handler.
 */
final class LivingPrivateConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // Visibility blocks user construction; no-op if reached (php-src internal ce handlers).
    }
}
