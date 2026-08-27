<?php
// #35412 — ClassConstFetch must seed IntlChar::* for thin AOT (peer #35408).
echo 'PROPERTY_ALPHABETIC=', IntlChar::PROPERTY_ALPHABETIC, "\n";
echo 'PROPERTY_WHITE_SPACE=', IntlChar::PROPERTY_WHITE_SPACE, "\n";
echo 'FOLD_CASE_DEFAULT=', IntlChar::FOLD_CASE_DEFAULT, "\n";
