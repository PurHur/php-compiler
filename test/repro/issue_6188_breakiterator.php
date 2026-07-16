<?php
// Forced-registration shape for #6188 — run via IntlModuleTest unit filter.
foreach (['IntlBreakIterator', 'IntlRuleBasedBreakIterator', 'IntlPartsIterator'] as $c) {
    echo $c, '=', class_exists($c, false) ? '1' : '0', "\n";
}
