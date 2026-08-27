<?php
// #35407 — ClassConstFetch must seed IntlListFormatter::* for thin AOT (peer #35401).
echo 'TYPE_AND=', IntlListFormatter::TYPE_AND, "\n";
echo 'WIDTH_WIDE=', IntlListFormatter::WIDTH_WIDE, "\n";
