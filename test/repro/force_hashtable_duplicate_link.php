<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\JIT\Builtin\HashTableDuplicateRuntime;
use PHPCompiler\JIT\Builtin\HashTableUnionRuntime;

$rt = new Runtime(Runtime::MODE_AOT);
$ctx = $rt->loadJitContext();
echo "context ok loadType=".$ctx->loadType."\n";
try {
    HashTableDuplicateRuntime::ensureLinked($ctx);
    echo "duplicate linked\n";
} catch (Throwable $e) {
    fwrite(STDERR, "DUP FAIL: ".$e->getMessage()."\n");
    fwrite(STDERR, $e->getFile().":".$e->getLine()."\n");
    exit(1);
}
try {
    HashTableUnionRuntime::ensureLinked($ctx);
    echo "union linked\n";
} catch (Throwable $e) {
    fwrite(STDERR, "UNION FAIL: ".$e->getMessage()."\n");
    fwrite(STDERR, $e->getFile().":".$e->getLine()."\n");
    exit(1);
}
$dup = $ctx->module->getNamedFunction('__hashtable__duplicate');
$uni = $ctx->module->getNamedFunction('__hashtable__union');
echo "dup bbs=".($dup?$dup->countBasicBlocks():0)." union bbs=".($uni?$uni->countBasicBlocks():0)."\n";
echo "OK\n";
