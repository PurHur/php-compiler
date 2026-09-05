<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Nullable object ctor args + ?? / parent::__construct nulls must match Zend (#36382).
 *
 * @group unit
 * @group llvm
 */
final class Issue36382NullObjectCtorArgAotTest extends TestCase
{
    private function compileAndRun(string $srcRel): string
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$srcRel;
        $bin = sys_get_temp_dir().'/issue_36382_'.getmypid().'_'.md5($srcRel);
        $compile = sprintf(
            '%s -d memory_limit=512M -d opcache.enable_cli=0 %s/bin/compile.php --no-cache -o %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($compile, $out, $cc);
        $this->assertSame(0, $cc, implode("\n", $out));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $rc);
            $this->assertSame(0, $rc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }

    public function testNullObjectCtorArgsCoalesceAndIdentical(): void
    {
        $this->assertSame(
            "C1\nP1\nrc_is_null:1\nRC\nP2:RC36382\nC2\n",
            $this->compileAndRun('test/repro/issue_36382_null_object_args.php')
        );
    }

    public function testParentConstructExplicitNullLocals(): void
    {
        $this->assertSame(
            "1\nC\nProxy\nRC\nProxyDone\nCDone\n1ok\n2\nD\nProxy\nRC\nProxyDone\nDDone\n2ok\n",
            $this->compileAndRun('test/repro/issue_36382_parent_defaults.php')
        );
    }

    public function testApppOnlyParentAfterCoalesceSameName(): void
    {
        $this->assertSame(
            "P\nPcr\nProxy\nRC\nProxyDone\nPDone\nOK\n",
            $this->compileAndRun('test/repro/issue_36382_appp_only.php')
        );
    }

    public function testNullableObjectParamIdenticalAndIsNull(): void
    {
        $got = $this->compileAndRun('test/repro/issue_36382_fn_null_object.php');
        $this->assertStringContainsString('eq:1', $got);
        $this->assertStringContainsString('is_null:1', $got);
        $this->assertStringContainsString('isset:0', $got);
        $this->assertStringContainsString('class:notobj', $got);
    }

    /** Builtin `object` prop accepts any instance; incomplete `$this` store (AppFactory). */
    public function testTypedObjectPropertyAssignAndThisInCtor(): void
    {
        $this->assertSame(
            "isset1\nA36382Obj\nParent36382This\nok\n",
            $this->compileAndRun('test/repro/issue_36382_typed_object_prop.php')
        );
    }

    public function testAppFactoryCreateShape(): void
    {
        $this->assertSame(
            "C1\nC2\nC3\nC4\nApp36382\nok:1\n",
            $this->compileAndRun('test/repro/issue_36382_appfactory_create_shape.php')
        );
    }

    /** Spill `self::make()` + `??` before `new` (AppFactory::create patch shape). */
    public function testNewWithMethodAndCoalesceArgTemps(): void
    {
        $this->assertSame(
            "1\n2\nDep36382NewCoal\nok\n",
            $this->compileAndRun('test/repro/issue_36382_new_method_coalesce_arg.php')
        );
    }

    /** `$md = new; $this->md = $md` on a nullable ctor param must survive return. */
    public function testCtorParamReuseAssignedIntoThis(): void
    {
        $this->assertSame(
            "R36382Md\nok\n",
            $this->compileAndRun('test/repro/issue_36382_ctor_param_reuse_prop.php')
        );
    }
}
