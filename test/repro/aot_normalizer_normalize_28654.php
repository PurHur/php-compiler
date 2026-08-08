<?php
$s = "e\u{0301}";
echo Normalizer::normalize($s, Normalizer::FORM_C) === "é" ? "ok\n" : "bad\n";
echo bin2hex(Normalizer::normalize($s, Normalizer::FORM_C)), "\n";
