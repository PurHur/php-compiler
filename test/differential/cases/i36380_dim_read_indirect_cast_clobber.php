<?php
// Short-circuit || / && with nested dim + preg_match must not clobber $Block['data']['type'] (#36380).
$Block = [
    'indent' => 0,
    'data' => [
        'type' => 'ul',
        'marker' => '- ',
        'markerType' => '-',
        'markerTypeRegex' => '\\-',
    ],
    'element' => ['elements' => [1]],
    'interrupted' => 1,
];
$Line = ['indent' => 0, 'text' => '- li', 'body' => '- li'];
$requiredIndent = 2;
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
echo ($cond ? '1' : '0'), "\n";
echo $Block['data']['type'], "\n";
echo isset($matches[1]) ? $matches[1] : '', "\n";
