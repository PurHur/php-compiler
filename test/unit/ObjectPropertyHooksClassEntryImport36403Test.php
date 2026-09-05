<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Concern extract must import {@see \PHPCompiler\VM\ClassEntry} — bare {@code ClassEntry}
 * in namespace {@code PHPCompiler} resolves to a non-existent {@code PHPCompiler\ClassEntry}
 * and TypeErrors on every typed-property class define (#36403 / #36776).
 *
 * @see php-src Zend/zend_object_handlers.c property hook linkage
 */
final class ObjectPropertyHooksClassEntryImport36403Test extends TestCase
{
    public function testTypedPropertyClassDefineDoesNotTypeErrorOnLinkPropertyHooks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class RouteCollectorMini {
    /** @var array */
    public $routes = [];
    public function map(array $methods, string $pattern, $callable) {
        $this->routes[] = [$methods, $pattern, $callable];
        echo 'MAP:', $methods[0], ':', $pattern, "\n";
        return $this;
    }
}
class AppMini {
    /** @var RouteCollectorMini */
    private $routeCollector;
    /** @var string */
    private $groupPattern = '';
    public function __construct() {
        $this->routeCollector = new RouteCollectorMini();
    }
    public function get(string $pattern, $callable) {
        return $this->map(['GET'], $pattern, $callable);
    }
    public function map(array $methods, string $pattern, $callable) {
        $pattern = $this->groupPattern . $pattern;
        return $this->routeCollector->map($methods, $pattern, $callable);
    }
}
$app = new AppMini();
$app->get('/hello', function ($request, $response, $args) {
    return 'hello';
});
echo "REGISTERED\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'object_property_hooks_classentry_import_36403.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("MAP:GET:/hello\nREGISTERED\n", $out);
    }

    public function testDebugInfoPathResolvesVmClassEntryConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Bag {
    public int $n = 1;
    public function __debugInfo(): array {
        return ['n' => $this->n];
    }
}
var_export((new Bag())->n);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'object_property_collect_classentry_import_36403.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("1\n", $out);
    }
}
