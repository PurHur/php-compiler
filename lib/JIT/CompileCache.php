<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheSemanticHash.php';
require_once __DIR__.'/CompileCachePartialEmitDemote.php';
require_once __DIR__.'/CompileCacheArtifactPersist.php';
require_once __DIR__.'/CompileCacheEditScaffoldPlan.php';
require_once __DIR__.'/CompileCacheEditScaffold.php';
require_once __DIR__.'/CompileCacheProjectIndex.php';
require_once __DIR__.'/CompileCacheKeyLayout.php';
require_once __DIR__.'/CompileCacheBitcodePersist.php';
require_once __DIR__.'/CompileCacheRecording.php';
require_once __DIR__.'/CompileCacheEditSession.php';
require_once __DIR__.'/CompileCacheProjectMembers.php';
require_once __DIR__.'/CompileCacheArtifactFacade.php';
require_once __DIR__.'/CompileCacheSemanticHashFacade.php';
require_once __DIR__.'/CompileCacheProjectIndexFacade.php';
require_once __DIR__.'/CompileCacheHubState.php';
require_once __DIR__.'/CompileCacheKeyLayoutFacade.php';

/**
 * On-disk MCJIT bitcode cache (issue #153).
 *
 * Persists verified LLVM bitcode keyed by source bytes + compiler fingerprint so a
 * second `bin/jit.php` process can skip LLVM IR lowering when inputs are unchanged.
 *
 * AOT warm rebuilds use {@see artifactPath()} / {@see objectPath()} for the fast path.
 * Full-module {@see bitcodePath()} also round-trips once void* lowers as i8* (#36387).
 *
 * Semantic hash / edit-strip planning lives in {@see CompileCacheSemanticHash}
 * (public hub delegates in {@see CompileCacheSemanticHashFacade});
 * partial-emit demote lives in {@see CompileCachePartialEmitDemote};
 * linked-binary / user-object mid-tier warm restore lives in {@see CompileCacheArtifactPersist};
 * edit-scaffold strip planning lives in {@see CompileCacheEditScaffoldPlan};
 * edit-scaffold restore + LLVM strip live in {@see CompileCacheEditScaffold} / {@see CompileCacheEditScaffoldStrip};
 * multi-file project index / entry→members map lives in {@see CompileCacheProjectIndex}
 * (public hub delegates in {@see CompileCacheProjectIndexFacade});
 * cache-entry paths / freshness / fingerprint live in {@see CompileCacheKeyLayout};
 * MCJIT bitcode restore/persist lives in {@see CompileCacheBitcodePersist};
 * cold-emit recording / symbol membership maps live in {@see CompileCacheRecording};
 * edit-scaffold session arm / state live in {@see CompileCacheEditSession};
 * project member path list / compile entry live in {@see CompileCacheProjectMembers};
 * artifact / object mid-tier warm restore live in {@see CompileCacheArtifactFacade};
 * shared recording / edit-scaffold / partial-emit fields live in {@see CompileCacheHubState}
 * (#36387 one-file-edit Done-when / #36403 size-budget split-TU).
 */
final class CompileCache
{
    use CompileCacheHubState;
    use CompileCacheKeyLayoutFacade;
    use CompileCacheEditScaffoldPlan;
    use CompileCacheEditScaffold;
    use CompileCacheBitcodePersist;
    use CompileCacheRecording;
    use CompileCacheEditSession;
    use CompileCacheProjectMembers;
    use CompileCacheArtifactFacade;
    use CompileCacheSemanticHashFacade;
    use CompileCacheProjectIndexFacade;

}
