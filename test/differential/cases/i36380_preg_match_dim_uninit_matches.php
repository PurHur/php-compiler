<?php
// #36380: preg_match subject dim + uninit &$matches must not steal the dim slot.
// @differential-skip-aot: thin AOT preg_match $matches path still under helper-runtime (#24115)
class P {
    public function run(array $Ex): void
    {
        preg_match('/x/', $Ex['text'], $m);
        echo json_encode([$m, $Ex['text']]), "\n";
        // Parsedown automatic_link shape (possessive + and).
        $Excerpt = ['text' => '<http://example.com>'];
        if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(\w++:\/{2}[^ >]++)>/i', $Excerpt['text'], $matches)) {
            echo json_encode([$matches[0], $matches[1], $Excerpt['text']]), "\n";
        }
    }
}
(new P())->run(['text' => 'x']);
