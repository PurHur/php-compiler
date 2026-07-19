<?php
// Repro for #20907 — IntlRuleBasedBreakIterator construct / getRules / getBinaryRules
declare(strict_types=1);

$rb = new IntlRuleBasedBreakIterator('[[:Letter:]];');
echo 'class=', get_class($rb), PHP_EOL;
try {
    $rb->setText('Ab');
    echo 'setText=ok', PHP_EOL;
    echo 'first=', $rb->first(), ' next=', $rb->next(), PHP_EOL;
} catch (Throwable $e) {
    echo 'setText: ', $e->getMessage(), PHP_EOL;
}
try {
    echo 'rules=', $rb->getRules(), PHP_EOL;
} catch (Throwable $e) {
    echo 'getRules: ', $e->getMessage(), PHP_EOL;
}
try {
    $b = $rb->getBinaryRules();
    echo 'binary_type=', gettype($b), PHP_EOL;
} catch (Throwable $e) {
    echo 'getBinaryRules: ', $e->getMessage(), PHP_EOL;
}
