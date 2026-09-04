<?php
function blockSetextHeader($Line, $Block = null)
{
    if (!isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted'])) {
        return 'skip';
    }
    if ($Line['indent'] < 4 and chop(chop($Line['text'], ' '), $Line['text'][0]) === '') {
        return 'setext:' . ($Line['text'][0] === '=' ? 'h1' : 'h2');
    }
    return 'no';
}

$cases = [
    ['text' => '- li', 'indent' => 0],
    ['text' => '-------- | --------', 'indent' => 0],
    ['text' => '-------|', 'indent' => 0],
    ['text' => '---', 'indent' => 0],
];
$Block = ['type' => 'Paragraph', 'element' => ['handler' => ['argument' => 'paragraph']]];
foreach ($cases as $Line) {
    echo json_encode($Line['text']) . ' => ' . blockSetextHeader($Line, $Block) . "\n";
}
