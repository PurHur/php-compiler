<?php
// #35408 — ClassConstFetch must seed UConverter::* for thin AOT (peer #35401).
echo 'REASON_ILLEGAL=', UConverter::REASON_ILLEGAL, "\n";
echo 'UTF8=', UConverter::UTF8, "\n";
echo 'US_ASCII=', UConverter::US_ASCII, "\n";
