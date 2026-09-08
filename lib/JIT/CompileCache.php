<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheSemanticFunctionConsume.php';
require_once __DIR__.'/CompileCacheSemanticFileParts.php';
require_once __DIR__.'/CompileCacheSemanticHash.php';
require_once __DIR__.'/CompileCachePartialEmitPruneGlobals.php';
require_once __DIR__.'/CompileCachePartialEmitSymbolProbe.php';
require_once __DIR__.'/CompileCachePartialEmitLlvm.php';
require_once __DIR__.'/CompileCachePartialEmitDemote.php';
require_once __DIR__.'/CompileCacheArtifactPersist.php';
require_once __DIR__.'/CompileCacheObjectLinkPersist.php';
require_once __DIR__.'/CompileCacheEditScaffold.php';
require_once __DIR__.'/CompileCacheProjectEntryMembers.php';
require_once __DIR__.'/CompileCacheProjectIndex.php';
require_once __DIR__.'/CompileCacheKeyLayout.php';
require_once __DIR__.'/CompileCacheBitcodeRestore.php';
require_once __DIR__.'/CompileCacheBitcodeAotStamp.php';
require_once __DIR__.'/CompileCacheBitcodePersist.php';
require_once __DIR__.'/CompileCacheRecordingMemberPath.php';
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
 * Semantic hash / edit-strip: {@see CompileCacheSemanticHash} (+ FileParts /
 * FunctionConsume / HashFacade). Partial-emit: {@see CompileCachePartialEmitDemote}
 * + {@see CompileCachePartialEmitLlvm} + {@see CompileCachePartialEmitSymbolProbe}
 * + {@see CompileCachePartialEmitPruneGlobals}.
 * Artifact mid-tier: {@see CompileCacheArtifactPersist}
 * / {@see CompileCacheObjectLinkPersist} / {@see CompileCacheArtifactFacade}. Edit-scaffold:
 * EditScaffold{,Plan,Restore,Strip} + EditSession. Project index + entry→members +
 * KeyLayout + BitcodeRestore/AotStamp/Persist + Recording (+ MemberPath) + HubState (#36387 / #36403).
 */
final class CompileCache
{
    use CompileCacheHubState;
    use CompileCacheKeyLayoutFacade;
    use CompileCacheEditScaffold;
    use CompileCacheBitcodeRestore;
    use CompileCacheBitcodeAotStamp;
    use CompileCacheBitcodePersist;
    use CompileCacheRecordingMemberPath;
    use CompileCacheRecording;
    use CompileCacheEditSession;
    use CompileCacheProjectMembers;
    use CompileCacheArtifactFacade;
    use CompileCacheSemanticHashFacade;
    use CompileCacheProjectIndexFacade;

}
