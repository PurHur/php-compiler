<?php
// AOT repro for #20760 — Locale::canonicalize + locale_canonicalize (follow-up #20738).
echo 'canon=', Locale::canonicalize('en-US'), "\n";
echo 'canon_case=', Locale::canonicalize('EN-us'), "\n";
echo 'proc=', locale_canonicalize('en-US'), "\n";
echo 'ok', "\n";
