<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Recursive element() with a dead/conditional assign on `$Element` must not alias
 * the caller's `$markup` CV (Parsedown pre>code / indented code, #36380).
 *
 * php-src: Zend/zend_execute.c — per-call CV table; recursive frames do not share locals.
 */
final class RecursiveElementCvPhi36380Test extends TestCase
{
    public function testNestedElementKeepsParentMarkupUnderVm(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/recursive_element_cv_phi_36380.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'recursive_element_cv_phi_36380.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertSame("OK\n", $out);
    }

    public function testDeadFalseAssignStillKeepsParentMarkup(): void
    {
        $code = <<<'PHP'
<?php
function element_nested(array $Element): string
{
    if (false) {
        $Element = $Element;
    }
    $hasName = isset($Element['name']);
    $markup = '';
    if ($hasName) {
        $markup .= '<' . $Element['name'];
    }
    if (isset($Element['element'])) {
        $markup .= $hasName ? '>' : '';
        $markup .= element_nested($Element['element']);
        $markup .= $hasName ? '</' . $Element['name'] . '>' : '';
    } else {
        $markup .= $hasName ? '>' : '';
        $markup .= (string) $Element['text'];
        $markup .= $hasName ? '</' . $Element['name'] . '>' : '';
    }
    return $markup;
}
$el = ['name' => 'pre', 'element' => ['name' => 'code', 'text' => "hello\n"]];
$out = element_nested($el);
echo $out === "<pre><code>hello\n</code></pre>" ? "OK\n" : ("BAD:" . var_export($out, true) . "\n");
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'dead_false_element.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertSame("OK\n", $out);
    }
}
