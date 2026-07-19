<?php
// #20907 — IntlRuleBasedBreakIterator __construct / getRules / getBinaryRules
$rb = new IntlRuleBasedBreakIterator('[[:Letter:]];');
echo 'class=', get_class($rb), "\n";
$rb->setText('Ab');
echo 'setText=ok', "\n";
echo 'first=', $rb->first(), "\n";
echo 'rules=', $rb->getRules(), "\n";
$b = $rb->getBinaryRules();
echo 'binary=', false === $b ? 'false' : gettype($b), "\n";
