<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Function-local static object property writes must persist across calls (#28040).
 *
 * Regression: releaseFrameObjectRefs called releaseDirectObject through the
 * DECLARE_FUNCTION_STATIC INDIRECT CV, dropping the static ObjectEntry refcount
 * and wipe properties via destroyForGc.
 */
final class FunctionStaticObjectPropPersistTest extends TestCase
{
    public function testPropertyIncrementPersistsAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = new stdClass;
  $x->n = ($x->n ?? 0) + 1;
  return $x->n;
}
echo f(), "|", f(), "|", f(), "|", f(), "\n";
PHP;
        $this->assertSame("1|2|3|4\n", $this->runVm($code));
    }

    public function testObjectIdentityStableAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $x = new stdClass;
  return spl_object_id($x);
}
echo f(), "|", f(), "|", f(), "\n";
PHP;
        $out = trim($this->runVm($code));
        $parts = explode('|', $out);
        $this->assertCount(3, $parts);
        $this->assertSame($parts[0], $parts[1]);
        $this->assertSame($parts[1], $parts[2]);
    }

    public function testDeclaredClassPropertyPersists(): void
    {
        $code = <<<'PHP'
<?php
class Box { public $n = 0; }
function f() {
  static $x = new Box;
  $x->n = $x->n + 1;
  return $x->n;
}
echo f(), "|", f(), "|", f(), "\n";
PHP;
        $this->assertSame("1|2|3\n", $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_object_prop_persist.php');
        ob_start();
        $rt->run($block);

        return (string) ob_get_clean();
    }
}
