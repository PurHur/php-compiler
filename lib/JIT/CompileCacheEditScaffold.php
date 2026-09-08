<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheEditScaffoldPlan.php';
require_once __DIR__.'/CompileCacheEditScaffoldStrip.php';
require_once __DIR__.'/CompileCacheEditScaffoldRestore.php';

/**
 * Edit-scaffold composition hub for AOT one-file-edit (#36387 / #36403).
 *
 * Keep/strip planning: {@see CompileCacheEditScaffoldPlan}.
 * LLVM strip/rebind/rebase: {@see CompileCacheEditScaffoldStrip}.
 * Bitcode restore orchestration: {@see CompileCacheEditScaffoldRestore}.
 * Move-only — no new C ABI. php-src analogy: Zend/zend_file_cache.c.
 */
trait CompileCacheEditScaffold
{
    use CompileCacheEditScaffoldStrip;
    use CompileCacheEditScaffoldPlan;
    use CompileCacheEditScaffoldRestore;
}
