<?php

declare(strict_types=1);

/**
 * Rename MessageTrait::$stream → $bodyStream for AOT (#36382).
 *
 * Stream and MessageTrait both declare `$stream`. When trait methods lower without a
 * composing class, tryPropertyFetchByRuntimeClass multi-candidate dispatch can pick the
 * wrong class / SEGV. Renaming the message body property is Zend-equivalent for Nyholm
 * Response/Request (private trait props; no external `$message->stream` API).
 *
 * php-src: Zend/zend_inheritance.c zend_do_traits_property_binding
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-message-trait-bodystream-36382.php <MessageTrait.php>\n");
    exit(1);
}
$t = file_get_contents($path);
if (false === $t) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}
if (str_contains($t, 'AOT (#36382): $bodyStream avoids Stream::$stream')) {
    fwrite(STDOUT, "MessageTrait.php bodyStream already patched (#36382)\n");
    exit(0);
}

$replacements = [
    '    /** @var StreamInterface|null */'."\n".'    private $stream;' =>
        '    /** @var StreamInterface|null */'."\n"
        .'    // AOT (#36382): $bodyStream avoids Stream::$stream name clash in runtime prop dispatch.'."\n"
        .'    private $bodyStream;',
    '$this->stream' => '$this->bodyStream',
    '$new->stream' => '$new->bodyStream',
];
foreach ($replacements as $old => $new) {
    if (!str_contains($t, $old) && !str_contains($old, 'bodyStream')) {
        // allow already partially applied
    }
}
if (!str_contains($t, 'private $stream;')) {
    fwrite(STDERR, "private \$stream not found in {$path}\n");
    exit(1);
}

$t = str_replace(
    '    /** @var StreamInterface|null */'."\n".'    private $stream;',
    '    /** @var StreamInterface|null */'."\n"
    .'    // AOT (#36382): $bodyStream avoids Stream::$stream name clash in runtime prop dispatch.'."\n"
    .'    private $bodyStream;',
    $t,
    $n1
);
if (1 !== $n1) {
    fwrite(STDERR, "decl replace failed ({$n1}) in {$path}\n");
    exit(1);
}
$t = str_replace('$this->stream', '$this->bodyStream', $t);
$t = str_replace('$new->stream', '$new->bodyStream', $t);
// Do not touch unrelated "stream" substrings in comments beyond our marker
if (false === file_put_contents($path, $t)) {
    fwrite(STDERR, "cannot write {$path}\n");
    exit(1);
}
fwrite(STDOUT, "patched MessageTrait \$stream → \$bodyStream for AOT (#36382)\n");
