<?php
/**
 * Repro for #23157 — Spoofchecker::setAllowedChars() is PHP 8.4+ only.
 *
 * Default / PROFILE=8.2: method_exists false (Zend 8.2/8.3).
 * PROFILE=8.4: method_exists true.
 *
 * When host php-intl is absent, Spoofchecker is withheld entirely (#19670) — this
 * script then prints class=0 and exits 0 (gate N/A). Force registration is covered
 * by VmSpoofcheckerTest.
 */
echo 'class=', class_exists('Spoofchecker') ? '1' : '0', "\n";
if (!class_exists('Spoofchecker')) {
    exit(0);
}
echo 'setAllowedChars=', method_exists(Spoofchecker::class, 'setAllowedChars') ? '1' : '0', "\n";
echo 'getAllowedChars=', method_exists(Spoofchecker::class, 'getAllowedChars') ? '1' : '0', "\n";
