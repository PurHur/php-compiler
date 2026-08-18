<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** ctype_* Reflection mixed $text (#26183, re-#23192). */
final class Issue26183CtypeMixedReflectionTest extends TestCase
{
    public function testCtypeReflectionMixedTextViaVm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['ctype_alnum', 'ctype_digit', 'ctype_alpha'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo "$fn \$".$p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    echo "$fn ret=", $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
var_export(ctype_alnum(text: 'abc'));
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_26183.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "ctype_alnum \$text:mixed\nctype_alnum ret=bool\nctype_digit \$text:mixed\nctype_digit ret=bool\nctype_alpha \$text:mixed\nctype_alpha ret=bool\ntrue\n",
            ob_get_clean()
        );
    }
}
