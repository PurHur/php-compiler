<?php
// #35397 — ClassConstFetch must seed IntlTimeZone::* for thin AOT (peer #35389).
echo 'DISPLAY_SHORT=', IntlTimeZone::DISPLAY_SHORT, "\n";
echo 'DISPLAY_LONG=', IntlTimeZone::DISPLAY_LONG, "\n";
echo 'TYPE_CANONICAL=', IntlTimeZone::TYPE_CANONICAL, "\n";
