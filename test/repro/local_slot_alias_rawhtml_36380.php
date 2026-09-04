<?php
/**
 * Local slot alias: assigning $permitRawHtml = true clobbers earlier $text (#36380).
 *
 * Parsedown::element sets $text from rawHtml then $permitRawHtml = !safeMode || …;
 * under VM $markup .= $text becomes (string)true → "1", so block-level_html fixtures
 * render as literal "1". Shape matches erusev/parsedown element().
 *
 * php-src: no language bug — Zend keeps distinct locals.
 */
// @differential-skip-aot: thin AOT still dumps core on some helper-runtime paths; VM is the signal
function element_parsedown_shape(array $Element): string
{
    $hasName = isset($Element['name']);
    $markup = '';
    if ($hasName) {
        $markup .= '<' . $Element['name'];
    }
    $permitRawHtml = false;
    if (isset($Element['text'])) {
        $text = $Element['text'];
    } elseif (isset($Element['rawHtml'])) {
        $text = $Element['rawHtml'];
        $allowRawHtmlInSafeMode = isset($Element['allowRawHtmlInSafeMode']) && $Element['allowRawHtmlInSafeMode'];
        $permitRawHtml = true;
        unset($allowRawHtmlInSafeMode);
    }
    $hasContent = isset($text) || isset($Element['element']) || isset($Element['elements']);
    if ($hasContent) {
        $markup .= $hasName ? '>' : '';
        if (!isset($Element['elements']) && !isset($Element['element'])) {
            if (!$permitRawHtml) {
                $markup .= htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
            } else {
                $markup .= $text;
            }
        }
        $markup .= $hasName ? '</' . $Element['name'] . '>' : '';
    }
    return $markup;
}

$el = ['rawHtml' => '<div>_content_</div>', 'autobreak' => true];
$out = element_parsedown_shape($el);
echo $out === '<div>_content_</div>' ? "OK\n" : ("BAD:" . var_export($out, true) . "\n");
