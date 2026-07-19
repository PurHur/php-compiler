<?php
// #20753 — Collator::compare / getSortKey / collator_compare after new Collator
$c = new Collator('en_US');
echo 'ab=', $c->compare('a', 'b'), PHP_EOL;
echo 'ba=', $c->compare('b', 'a'), PHP_EOL;
echo 'aa=', $c->compare('a', 'a'), PHP_EOL;
echo 'fn=', (int) function_exists('collator_compare'), PHP_EOL;
echo 'proc=', collator_compare($c, 'a', 'b'), PHP_EOL;
$sk = $c->getSortKey('abc');
echo 'sortkey=', (\is_string($sk) && '' !== $sk) ? 'ok' : 'bad', PHP_EOL;
