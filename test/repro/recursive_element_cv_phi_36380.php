<?php
/**
 * Recursive element() with conditional assign on $Element (#36380).
 *
 * Parsedown::element does `if ($this->safeMode) { $Element = sanitise($Element); }`
 * then `$markup .= $this->element($Element['element'])`. Merge/PHI blocks set
 * inheritUndefinedLocals; findVariableInParentFramesByName walked past the activation
 * entry into the recursive caller (same Func) and aliased `$markup` — VM rendered
 * duplicated `<code>…</code>` without the outer `<pre` prefix.
 *
 * php-src: Zend/zend_execute.c — per-call CV table (no cross-frame local alias).
 */
// @differential-skip-aot: helper-runtime cache still blocks thin AOT for this shape (#15889)
function element_nested(array $Element, bool $safeMode = false): string
{
    if ($safeMode) {
        $Element = $Element; // identity — still creates CFG PHI like sanitiseElement
    }

    $hasName = isset($Element['name']);
    $markup = '';

    if ($hasName) {
        $markup .= '<' . $Element['name'];
    }

    $hasContent = isset($Element['text']) || isset($Element['element']);

    if ($hasContent) {
        $markup .= $hasName ? '>' : '';

        if (isset($Element['element'])) {
            $markup .= element_nested($Element['element'], $safeMode);
        } else {
            $markup .= (string) $Element['text'];
        }

        $markup .= $hasName ? '</' . $Element['name'] . '>' : '';
    } elseif ($hasName) {
        $markup .= ' />';
    }

    return $markup;
}

$el = [
    'name' => 'pre',
    'element' => [
        'name' => 'code',
        'text' => "hello\n",
    ],
];
$out = element_nested($el, false);
$expect = "<pre><code>hello\n</code></pre>";
echo $out === $expect ? "OK\n" : ("BAD:" . var_export($out, true) . "\n");
