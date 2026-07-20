<?php
/** Repro #21481 AOT — money_format/convert_cyr_string phantoms. */
echo function_exists('money_format') ? "money:exists\n" : "money:missing\n";
echo function_exists('convert_cyr_string') ? "cyr:exists\n" : "cyr:missing\n";
