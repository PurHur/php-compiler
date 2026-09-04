<?php
/**
 * #36380: does comparing $Block['data']['type'] === 'ul' inside an and/or
 * chain (with preg_match) clobber the nested string to boolean true?
 */
$Block = array(
    'indent' => 0,
    'data' => array(
        'type' => 'ul',
        'marker' => '- ',
        'markerType' => '-',
        'markerTypeRegex' => '\\-',
    ),
    'element' => array('elements' => array(1)),
    'interrupted' => 1,
);

$Line = array('indent' => 0, 'text' => '- li', 'body' => '- li');
$requiredIndent = 2;

echo "before=", var_export($Block['data']['type'], true), "\n";

$matches = null;
$cond = ($Line['indent'] < $requiredIndent
    and (
        (
            $Block['data']['type'] === 'ol'
            and preg_match('/^[0-9]++'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
        ) or (
            $Block['data']['type'] === 'ul'
            and preg_match('/^'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
        )
    )
);

echo "cond=", var_export($cond, true), "\n";
echo "after=", var_export($Block['data']['type'], true), "\n";
echo "matches=", json_encode($matches), "\n";
