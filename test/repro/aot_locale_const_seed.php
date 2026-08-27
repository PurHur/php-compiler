<?php
// #35416 — ClassConstFetch must seed Locale::* for thin AOT (peer #35413).
echo 'ACTUAL_LOCALE=', Locale::ACTUAL_LOCALE, "\n";
echo 'VALID_LOCALE=', Locale::VALID_LOCALE, "\n";
