<?php

declare(strict_types=1);

// UConverter::transcode() options['to_subst'] (#25201, php-src ext/intl/converter/converter.cpp)
echo bin2hex(UConverter::transcode('é', 'ASCII', 'UTF-8', ['to_subst' => '?'])), "\n";
echo bin2hex(UConverter::transcode('é', 'ASCII', 'UTF-8')), "\n";
echo bin2hex(UConverter::transcode("\x80", 'ASCII', 'UTF-8', ['to_subst' => '?'])), "\n";
