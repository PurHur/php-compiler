<?php
// #35402 — ClassConstFetch must seed IntlBreakIterator::* for thin AOT (peer #35397).
echo 'DONE=', IntlBreakIterator::DONE, "\n";
echo 'WORD_LETTER=', IntlBreakIterator::WORD_LETTER, "\n";
echo 'LINE_HARD=', IntlBreakIterator::LINE_HARD, "\n";
