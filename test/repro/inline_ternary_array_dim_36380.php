<?php
/**
 * Array literal: dim-fetch element + nested inline ternary must not share a VM slot (#36380).
 *
 * Parsedown::blockList builds:
 *   ['indent' => $Line['indent'], 'data' => [..., 'markerType' => $cond ? $a : $b], ...]
 * php-cfg evaluates elements before INIT_ARRAY; a later ?: JUMPIF reused the indent temp,
 * so the packed HT aliased the ternary result and `$Block['data']['markerTypeRegex'] = …`
 * grew until OOM (HashTable::duplicate / grow).
 *
 * php-src: Zend/zend_compile.c zend_compile_array — element values are copied into the
 * array, not live aliases of reusable operand temps.
 */
function blockList_shape(array $Line, ?array $CurrentBlock = null): ?array
{
    list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]{1,9}+[.\)]');
    if (!preg_match('/^('.$pattern.'([ ]++|$))(.*+)/', $Line['text'], $matches)) {
        return null;
    }
    $contentIndent = strlen($matches[2]);
    if ($contentIndent >= 5) {
        $contentIndent -= 1;
        $matches[1] = substr($matches[1], 0, -$contentIndent);
        $matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
    } elseif ($contentIndent === 0) {
        $matches[1] .= ' ';
    }
    $markerWithoutWhitespace = strstr($matches[1], ' ', true);
    $Block = array(
        'indent' => $Line['indent'],
        'pattern' => $pattern,
        'data' => array(
            'type' => $name,
            'marker' => $matches[1],
            'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
        ),
        'element' => array(
            'name' => $name,
            'elements' => array(),
        ),
    );
    $Block['data']['markerTypeRegex'] = preg_quote($Block['data']['markerType'], '/');
    $Block['li'] = array(
        'name' => 'li',
        'handler' => array(
            'function' => 'li',
            'argument' => !empty($matches[3]) ? array($matches[3]) : array(),
            'destination' => 'elements',
        ),
    );
    $Block['element']['elements'][] =& $Block['li'];

    return $Block;
}

$Line = ['body' => '- a', 'indent' => 0, 'text' => '- a'];
$b = blockList_shape($Line, null);
echo isset($b['data']['markerTypeRegex']) && $b['data']['markerTypeRegex'] === '\\-'
    && $b['indent'] === 0
    && count($b['element']['elements']) === 1
    ? "OK\n"
    : ("BAD:".json_encode($b)."\n");
