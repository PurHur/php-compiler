<?php
declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #23527 — readonly class __clone Error must abort clone (no resume after catch). */
final class ReadonlyClassCloneAbortTest extends TestCase
{
    public function testReadonlyClassCloneErrorDoesNotResumeAfterCatch(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_23527_readonly_class_clone_abort.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_23527_readonly_class_clone_abort.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "START\nBEFORE_CLONE\nIN_CLONE\nCATCH:Error:Cannot modify readonly property C::\$s\nEND\n",
            $output
        );
    }

    public function testThrowInsideCloneInnerCatchStillCompletesClone(): void
    {
        $code = <<<'PHP'
<?php
class C {
  public function __construct(public string $s) {}
  public function __clone() {
    echo "IN_CLONE\n";
    try {
      throw new Error("boom");
    } catch (Throwable $e) {
      echo "INNER_CATCH:", $e->getMessage(), "\n";
    }
    echo "AFTER_INNER\n";
  }
}
echo "START\n";
try {
  $a = new C("hi");
  $b = clone $a;
  echo "AFTER_CLONE:", $b->s, "\n";
} catch (Throwable $e) {
  echo "OUTER_CATCH:", $e->getMessage(), "\n";
}
echo "END\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'clone_inner_catch.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "START\nIN_CLONE\nINNER_CATCH:boom\nAFTER_INNER\nAFTER_CLONE:hi\nEND\n",
            $output
        );
    }
}
