<?php
// #35413 — ClassConstFetch must seed IntlChar::* for thin AOT (peer #35408).
echo 'PROPERTY_ALPHABETIC=', IntlChar::PROPERTY_ALPHABETIC, "\n";
echo 'PROPERTY_UPPERCASE=', IntlChar::PROPERTY_UPPERCASE, "\n";
echo 'FOLD_CASE_EXCLUDE_SPECIAL_I=', IntlChar::FOLD_CASE_EXCLUDE_SPECIAL_I, "\n";
