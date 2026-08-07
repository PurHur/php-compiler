<?php

declare(strict_types=1);

/**
 * Repro #28386 — XMLParser must be final (php-src ext/xml/xml.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28386_xmlparser_final.php
 */
xml_parser_create();
echo 'isFinal=', var_export((new ReflectionClass(XMLParser::class))->isFinal(), true), "\n";
eval('class BadXmlParser extends XMLParser {}');
echo "EXTENDED_OK\n";
