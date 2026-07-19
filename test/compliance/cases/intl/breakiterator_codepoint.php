<?php
// #20822 — IntlCodePointBreakIterator + createCodePointInstance (php-src codepointiterator)
echo 'class=', (int) class_exists('IntlCodePointBreakIterator', false), "\n";
echo 'factory=', (int) method_exists('IntlBreakIterator', 'createCodePointInstance'), "\n";
$bi = IntlBreakIterator::createCodePointInstance();
echo 'inst=', get_class($bi), "\n";
$bi->setText("A\u{1F600}B");
$out = [];
for ($p = $bi->first(); $p !== IntlBreakIterator::DONE; $p = $bi->next()) {
    $out[] = $p;
    if (count($out) > 8) {
        break;
    }
}
echo 'breaks=', json_encode($out), "\n";
$bi2 = IntlBreakIterator::createCodePointInstance();
$bi2->setText("A\u{1F600}B");
echo 'first=', $bi2->first(), ' lastCP=', $bi2->getLastCodePoint(), "\n";
echo 'n1=', $bi2->next(), ' lastCP=', $bi2->getLastCodePoint(), "\n";
echo 'n2=', $bi2->next(), ' lastCP=', $bi2->getLastCodePoint(), "\n";
echo 'n3=', $bi2->next(), ' lastCP=', $bi2->getLastCodePoint(), "\n";
echo 'n4=', $bi2->next(), ' lastCP=', $bi2->getLastCodePoint(), "\n";
