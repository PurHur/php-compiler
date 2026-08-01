<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #26379 — promoted #[\SensitiveParameter] stays on parameter, not property */
#[Group('SensitiveParameterPromotedReflection')]
final class SensitiveParameterPromotedReflectionTest extends TestCase
{
    public function testPromotedSensitiveParameterReflectionAndTrace(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
ini_set('zend.exception_ignore_args', '0');
class C {
    public function __construct(
        #[\SensitiveParameter]
        public string $password,
    ) {
        throw new Exception('fail');
    }
}
try {
    new C('secret');
} catch (Throwable $e) {
    $args = $e->getTrace()[0]['args'] ?? null;
    echo null === $args ? "noargs\n" : get_class($args[0])."\n";
    echo str_contains($e->getTraceAsString(), 'secret') ? "LEAK\n" : "safe\n";
}
$propNames = array_map(
    static fn ($a) => $a->getName(),
    (new ReflectionProperty(C::class, 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $propNames, true) ? "prop_has_sens\n" : "prop_no_sens\n";
$paramNames = array_map(
    static fn ($a) => $a->getName(),
    (new ReflectionParameter([C::class, '__construct'], 'password'))->getAttributes()
);
echo in_array('SensitiveParameter', $paramNames, true) ? "param_has_sens\n" : "param_no_sens\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_26379_sensitive_promoted.php'));
        $this->assertSame(
            "SensitiveParameterValue\nsafe\nprop_no_sens\nparam_has_sens\n",
            ob_get_clean()
        );
    }
}
