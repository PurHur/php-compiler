<?php

declare(strict_types=1);

namespace PHPCompiler\SourcePreprocessor;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\InheritanceVariance;
use PHPCompiler\CompilerVersion;

/**
 * Strip PHP 8.4 property-hook blocks for nikic/php-parser v4 and inject hook methods.
 *
 * php-src: Zend/zend_compile.c property hook lowering (issue #3145, #5404 short get/set =>).
 */
final class PropertyHooks
{
    /** php-src: Zend/zend_compile.c — hooks apply to object properties only (#24281, re-#9725). */
    public const STATIC_HOOK_COMPILE_ERROR = 'Cannot declare hooks for static property';

    /**
     * php-src: Zend/zend_compile.c zend_add_member_modifier — GH-17916 / #29424.
     *
     * {@code final} + {@code abstract} on a hooked property is a contradiction (subclasses
     * must implement, but final forbids override).
     */
    public const FINAL_ABSTRACT_PROPERTY_COMPILE_ERROR = 'Cannot use the final modifier on an abstract property';

    /**
     * php-src: Zend/zend_compile.c zend_add_member_modifier — #29425.
     *
     * {@code final} + {@code private} (read visibility) is illegal; {@code private(set)} is not
     * the same as private read visibility and remains allowed with {@code final}.
     */
    public const FINAL_PRIVATE_PROPERTY_COMPILE_ERROR = 'Property cannot be both final and private';

    /** php-src: Zend/zend_compile.c — `private(set)` decl + hook block requires set hook (#12203). */
    public const ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE = 'syntax error, unexpected token ")", expecting amp';

    /**
     * php-src: Zend/zend_inheritance.c zend_verify_hooked_property — #29426 / php-src#19845.
     *
     * Virtual hooked property with asymmetric set-visibility must declare both get and set hooks;
     * get-only → "Read-only …", set-only → "Write-only …".
     */
    public const READONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR = 'Read-only virtual property %s::$%s must not specify asymmetric visibility';

    /** @see READONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR */
    public const WRITEONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR = 'Write-only virtual property %s::$%s must not specify asymmetric visibility';

    /**
     * php-src: Zend/zend_compile.c zend_modifier_token_to_flag(ZEND_MODIFIER_TARGET_PROPERTY_HOOK) (#29388).
     *
     * Visibility and asymmetric-set tokens are illegal on property hooks; only {@code final} is allowed.
     */
    public const HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR = 'Cannot use the %s modifier on a property hook';

    /** @see HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR */
    public const HOOK_ASYMMETRIC_SET_MODIFIER_COMPILE_ERROR = 'Cannot use the %s(set) modifier on a property hook';

    /**
     * php-src: Zend/zend_compile.c + zend_hooked_property_variance_error_ex (#29419).
     *
     * Explicit {@code set($value)} / {@code set(T $value)} must match property typing:
     * typed property XOR typed set-param is illegal; both typed requires contravariance
     * (set-param same or wider than the property type). Short {@code set \{ \}} / {@code set =>}
     * omit the param list and synthesize a typed {@code $value} (not rejected here).
     */
    public const SET_HOOK_VALUE_TYPE_COMPAT_ERROR = 'Type of parameter $%s of hook %s::$%s::set must be compatible with property type';

    /**
     * php-src: Zend/zend_compile.c — set-hook param list must have exactly one child (#29443).
     *
     * Zend wording keeps the plural {@code parameters} even for "one".
     * Message shape: {@code set hook of property C::$x must accept exactly one parameters}.
     * Applies only when an explicit {@code (…)} list is present — shorthand {@code set \{ \}} /
     * {@code set =>} synthesize {@code $value} and are not rejected here.
     */
    public const SET_HOOK_ARITY_COMPILE_ERROR = '%s hook of property %s::$%s must accept exactly one parameters';

    /**
     * php-src: Zend/zend_compile.c — set-hook param must not be {@code ZEND_PARAM_REF} (#29442).
     *
     * Message shape: {@code Parameter $value of set hook C::$x must not be pass-by-reference}.
     */
    public const SET_HOOK_PARAM_BY_REF_COMPILE_ERROR = 'Parameter $%s of %s hook %s::$%s must not be pass-by-reference';

    /**
     * php-src: Zend/zend_property_hooks.c / zend_compile.c — get hook must not declare a parameter list (#29444).
     *
     * {@code get($unused)}, empty {@code get()}, and {@code get ($len) => …} are all compile-fatal;
     * legal forms are {@code get =>}, {@code get \{ … \}}, and {@code &get} without parentheses.
     */
    public const GET_HOOK_PARAMETER_LIST_COMPILE_ERROR = 'get hook of property %s::$%s must not have a parameter list';

    /** Zend 8.2 reference profile — default initializer + hook block (#12574). */
    public const REFERENCE_PROFILE_UNEXPECTED_ARROW = 'syntax error, unexpected token "=>"';

    /** Zend 8.2 reference profile — hook block after property name (#12574). */
    public const REFERENCE_PROFILE_UNEXPECTED_BRACE = 'syntax error, unexpected token "{", expecting "," or ";"';

    /** php-src: zend_verify_hooked_property — virtual hooked property + explicit default (#16861, #12995). */
    public const VIRTUAL_HOOKED_DEFAULT_COMPILE_ERROR = 'Cannot specify default value for virtual hooked property %s::$%s';

    /**
     * php-src: Zend/zend_language_parser.y — promoted param hooks then `=` is a ParseError (#29242, re-#7313).
     *
     * Default *before* the hook block remains valid (`public int $x = 1 { get … }`).
     */
    public const PROMOTED_HOOK_DEFAULT_AFTER_PARSE_ERROR = 'syntax error, unexpected token "=", expecting ")"';

    /**
     * php-src: Zend/zend_inheritance.c zend_verify_hooked_property — backed `&get` + `set` (#29230).
     *
     * Message uses {@code Class::prop} (not {@code Class::$prop}).
     */
    public const BACKED_GET_BYREF_WITH_SET_COMPILE_ERROR = 'Get hook of backed property %s::%s with set hook may not return by reference';

    private const SET_METHOD_PREFIX = '__phpc_property_set_';
    private const GET_METHOD_PREFIX = '__phpc_property_get_';
    private const UNSET_METHOD_PREFIX = '__phpc_property_unset_';

    /** @var array<string, array<string, array{set?: string, get?: string}>> lcClass => prop => hook method names */
    private array $registry = [];

    /**
     * @return array{0: string, 1: array<string, array<string, array{set?: string, get?: string}>>}
     */
    public function process(string $code, string $filename = 'unknown'): array
    {
        $this->registry = [];
        $offset = 0;
        // Recompute length each iteration: rewritten hook methods grow the buffer, so a
        // stale strlen would skip later hooked classes in the same file (#21296).
        while ($offset < strlen($code)) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos, $declKind, $declName] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $header = substr($code, $declPos, $braceOpen - $declPos);
            $isAbstractClass = 'class' === $declKind
                && (bool) preg_match('/\babstract\s+(?:readonly\s+)?class\b/i', $header);
            $processedBody = $this->processClassBody(
                $body,
                strtolower($declName),
                $declName,
                $filename,
                $bodyStart + 1,
                $code,
                $declKind,
                $isAbstractClass
            );
            $code = substr($code, 0, $bodyStart + 1).$processedBody.substr($code, $bodyEnd);
            $offset = $bodyStart + 1 + strlen($processedBody);
        }

        return [$code, $this->registry];
    }

    /**
     * First property-hook syntax for Zend 8.2 reference-profile rejection (#12574).
     *
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileHookSyntaxError(string $code): ?array
    {
        return (new self())->locateReferenceProfileHookSyntaxError($code);
    }

    /**
     * Default initializer + virtual hook block on forward profile; reference-profile parse errors (#12995, #16861).
     *
     * @return array{line: int, message: string}|null
     */
    public static function defaultInitializerWithHookBlockSyntaxError(string $code): ?array
    {
        return (new self())->locateDefaultInitializerWithHookBlockSyntaxError($code);
    }

    /**
     * Static property + hook block on forward profile; php-src rejects at compile time (#24281).
     *
     * @return array{line: int, message: string}|null
     */
    public static function staticPropertyHookSyntaxError(string $code): ?array
    {
        return (new self())->locateStaticPropertyHookSyntaxError($code);
    }

    public static function virtualHookedDefaultCompileError(string $className, string $propName): string
    {
        return sprintf(self::VIRTUAL_HOOKED_DEFAULT_COMPILE_ERROR, $className, $propName);
    }

    public static function readonlyVirtualAsymmetricVisibilityCompileError(string $className, string $propName): string
    {
        return sprintf(self::READONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR, $className, $propName);
    }

    public static function writeonlyVirtualAsymmetricVisibilityCompileError(string $className, string $propName): string
    {
        return sprintf(self::WRITEONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR, $className, $propName);
    }

    public static function backedGetByRefWithSetCompileError(string $className, string $propName): string
    {
        return sprintf(self::BACKED_GET_BYREF_WITH_SET_COMPILE_ERROR, $className, $propName);
    }

    /** Zend 8.2 reference-profile parse diagnostic (#18019, zend_language_parser.y). */
    public static function referenceProfileHookRejectMessage(string $zendSyntaxDetail): string
    {
        return $zendSyntaxDetail;
    }

    private function locateReferenceProfileHookSyntaxError(string $code): ?array
    {
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos, , ] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $error = $this->locateHookSyntaxErrorInBody($code, $bodyStart + 1, $body);
            if (null !== $error) {
                return $error;
            }
            $offset = $bodyEnd + 1;
        }

        return null;
    }

    private function locateDefaultInitializerWithHookBlockSyntaxError(string $code): ?array
    {
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos, , $declName] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $error = $this->locateHookSyntaxErrorInBody($code, $bodyStart + 1, $body, true, $declName);
            if (null !== $error) {
                return $error;
            }
            $offset = $bodyEnd + 1;
        }

        return null;
    }

    /**
     * @return array{line: int, message: string}|null
     */
    private function locateStaticPropertyHookSyntaxError(string $code): ?array
    {
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $decl = $this->findNextDeclarable($code, $offset);
            if (null === $decl) {
                break;
            }
            [$declPos, , ] = $decl;
            $braceOpen = strpos($code, '{', $declPos);
            if (false === $braceOpen) {
                break;
            }
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $error = $this->locateStaticPropertyHookInBody($code, $bodyStart + 1, $body);
            if (null !== $error) {
                return $error;
            }
            $offset = $bodyEnd + 1;
        }

        return null;
    }

    /**
     * @return array{line: int, message: string}|null
     */
    private function locateStaticPropertyHookInBody(string $fullCode, int $bodyOffsetInFull, string $body): ?array
    {
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $hook = $this->findNextPropertyHookDecl($body, $offset);
            if (null === $hook) {
                return null;
            }
            [$prop, $varStart, $hookOpen] = $hook;
            $declPrefix = substr($body, max(0, $varStart - 200), $varStart - max(0, $varStart - 200));
            $propDeclHead = rtrim(substr($body, $varStart, $hookOpen - $varStart));
            [$priorMembers, $ownDeclPrefix] = $this->splitPropertyDeclPrefix($declPrefix);
            $ownDeclHead = $ownDeclPrefix.$propDeclHead;
            if (preg_match('/\bstatic\b/', $ownDeclHead)) {
                return [
                    'line' => self::lineAtOffset($fullCode, $bodyOffsetInFull + $hookOpen),
                    'message' => self::STATIC_HOOK_COMPILE_ERROR,
                ];
            }
            $hookSpan = $this->matchingBraceSpan($body, $hookOpen);
            $offset = null === $hookSpan ? $hookOpen + 1 : $hookSpan[1] + 1;
        }

        return null;
    }

    /**
     * @return array{line: int, message: string}|null
     */
    private function locateHookSyntaxErrorInBody(
        string $fullCode,
        int $bodyOffsetInFull,
        string $body,
        bool $defaultInitializerOnly = false,
        string $declName = ''
    ): ?array {
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $hook = $this->findNextPropertyHookDecl($body, $offset);
            if (null === $hook) {
                return null;
            }
            [$prop, $varStart, $hookOpen] = $hook;
            $afterVar = $varStart + 1 + strlen($prop);
            $between = substr($body, $afterVar, $hookOpen - $afterVar);
            $hasDefault = str_contains($between, '=');
            $absHookOpen = $bodyOffsetInFull + $hookOpen;
            if ($defaultInitializerOnly && !$hasDefault) {
                $hookSpan = $this->matchingBraceSpan($body, $hookOpen);
                $offset = null === $hookSpan ? $hookOpen + 1 : $hookSpan[1] + 1;
                continue;
            }
            if ($hasDefault) {
                $hookSpan = $this->matchingBraceSpan($body, $hookOpen);
                if (null === $hookSpan) {
                    return [
                        'line' => self::lineAtOffset($fullCode, $absHookOpen),
                        'message' => self::referenceProfileHookRejectMessage(self::REFERENCE_PROFILE_UNEXPECTED_BRACE),
                    ];
                }
                [$open, $close] = $hookSpan;
                $hookSource = substr($body, $open + 1, $close - $open - 1);
                if ($defaultInitializerOnly && CompilerVersion::supportsPropertyHooks()) {
                    $declPrefix = substr($body, max(0, $varStart - 200), $varStart - max(0, $varStart - 200));
                    $propDeclHead = rtrim(substr($body, $varStart, $hookOpen - $varStart));
                    [, $ownDeclPrefix] = $this->splitPropertyDeclPrefix($declPrefix);
                    $ownDeclHead = $ownDeclPrefix.$propDeclHead;
                    $propertyType = $this->propertyTypeFromDeclHead($ownDeclHead);
                    $isStatic = (bool) preg_match('/\bstatic\b/', $ownDeclHead);
                    $classDisplay = '' !== $declName ? $declName : 'c';
                    try {
                        [, $usesBacking] = $this->lowerHooks(
                            $hookSource,
                            $prop,
                            strtolower($classDisplay),
                            $isStatic,
                            true,
                            $propertyType,
                            'unknown',
                            $fullCode,
                            $absHookOpen,
                            $classDisplay
                        );
                    } catch (CompileFatal $e) {
                        // Surface set-hook type / other hook CompileFatals via the rejector so
                        // the real filename is attached (PropertyHookSyntaxRejector).
                        return [
                            'line' => $e->sourceLine,
                            'message' => $e->getMessage(),
                        ];
                    }
                    if ($usesBacking) {
                        $offset = $close + 1;
                        continue;
                    }

                    return [
                        'line' => self::lineAtOffset($fullCode, $absHookOpen),
                        'message' => self::virtualHookedDefaultCompileError($declName, $prop),
                    ];
                }
                $arrowRel = $this->findFirstFatArrowOffset($hookSource);
                if (null !== $arrowRel) {
                    $absArrow = $bodyOffsetInFull + $open + 1 + $arrowRel;

                    return [
                        'line' => self::lineAtOffset($fullCode, $absArrow),
                        'message' => self::referenceProfileHookRejectMessage(self::REFERENCE_PROFILE_UNEXPECTED_ARROW),
                    ];
                }
            }

            return [
                'line' => self::lineAtOffset($fullCode, $absHookOpen),
                'message' => self::referenceProfileHookRejectMessage(self::REFERENCE_PROFILE_UNEXPECTED_BRACE),
            ];
        }

        return null;
    }

    private function findFirstFatArrowOffset(string $code): ?int
    {
        $len = strlen($code);
        $inString = false;
        $stringChar = '';
        for ($i = 0; $i + 1 < $len; ++$i) {
            $ch = $code[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('=' === $ch && '>' === $code[$i + 1]) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null [position, kind, name]
     */
    private function findNextDeclarable(string $code, int $from): ?array
    {
        $len = strlen($code);
        $searchFrom = $from;
        while ($searchFrom < $len) {
            $candidate = null;
            foreach ([
                'class' => '/\bclass\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
                'interface' => '/\binterface\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
                'trait' => '/\btrait\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/',
            ] as $kind => $pattern) {
                if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE, $searchFrom)) {
                    continue;
                }
                $pos = $m[0][1];
                if (null === $candidate || $pos < $candidate[0]) {
                    $candidate = [$pos, $kind, $m[1][0], $pos + strlen($m[0][0])];
                }
            }
            if (null === $candidate) {
                return null;
            }
            [$pos, $kind, $name, $nameEnd] = $candidate;
            if ($this->offsetInNonCodeContext($code, $pos)) {
                $searchFrom = $pos + 1;

                continue;
            }
            if ($this->isDeclarableHeader($code, $nameEnd)) {
                return [$pos, $kind, $name];
            }
            $searchFrom = $pos + 1;
        }

        return null;
    }

    /**
     * True when $offset sits inside a string literal or comment (#7030 — do not rewrite eval() strings).
     */
    private function offsetInNonCodeContext(string $code, int $offset): bool
    {
        $len = strlen($code);
        $i = 0;
        $inString = false;
        $stringQuote = '';
        $inLineComment = false;
        $inBlockComment = false;
        while ($i < $offset && $i < $len) {
            if ($inLineComment) {
                if ("\n" === $code[$i]) {
                    $inLineComment = false;
                }
                ++$i;

                continue;
            }
            if ($inBlockComment) {
                if ('*' === $code[$i] && $i + 1 < $len && '/' === $code[$i + 1]) {
                    $inBlockComment = false;
                    $i += 2;

                    continue;
                }
                ++$i;

                continue;
            }
            if ($inString) {
                if ('\\' === $code[$i]) {
                    $i += 2;

                    continue;
                }
                if ($code[$i] === $stringQuote) {
                    $inString = false;
                }
                ++$i;

                continue;
            }
            if ('/' === $code[$i] && $i + 1 < $len) {
                if ('/' === $code[$i + 1]) {
                    $inLineComment = true;
                    $i += 2;

                    continue;
                }
                if ('*' === $code[$i + 1]) {
                    $inBlockComment = true;
                    $i += 2;

                    continue;
                }
            }
            if ('#' === $code[$i]) {
                $inLineComment = true;
                ++$i;

                continue;
            }
            if ('"' === $code[$i] || '\'' === $code[$i]) {
                $inString = true;
                $stringQuote = $code[$i];
                ++$i;

                continue;
            }
            ++$i;
        }

        return $inString || $inLineComment || $inBlockComment;
    }

    private function isDeclarableHeader(string $code, int $from): bool
    {
        $len = strlen($code);
        $i = $from;
        while ($i < $len && ctype_space($code[$i])) {
            ++$i;
        }
        if ($i >= $len) {
            return false;
        }
        if ('{' === $code[$i]) {
            return true;
        }

        return (bool) preg_match(
            '/^(?:extends|implements|sealed|readonly)\b[\s\S]*?\{/i',
            substr($code, $i)
        );
    }

    /**
     * True when a hooked `$prop { ... }` sits in a constructor promoted parameter list (#7313).
     */
    private function isPromotedConstructorParam(
        string $body,
        int $declStart,
        int $hookClose,
        string $declPrefix,
        string $propDeclHead
    ): bool {
        if (!preg_match('/\b(public|protected|private)\b/', $declPrefix.$propDeclHead)) {
            return false;
        }
        $prefix = substr($body, 0, $declStart);
        if (!preg_match('/\bfunction\s+__construct\s*\(/s', $prefix, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $constructOpen = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $segment = substr($body, $constructOpen, $hookClose - $constructOpen + 1);
        $depth = 0;
        $len = strlen($segment);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $segment[$i];
            if ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return false;
                }
            }
        }

        return $depth > 0;
    }

    /**
     * Zend rejects `} = <expr>` after a promoted-parameter hook block (zend_language_parser.y, #29242).
     *
     * Skips whitespace and block comments between `}` and `=` so comment-padded forms still fail.
     */
    private function rejectPromotedCtorHookDefaultAfter(
        string $body,
        int $hookClose,
        string $filename,
        string $fullCode,
        int $bodyOffsetInFile
    ): void {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        $len = strlen($body);
        $i = $hookClose + 1;
        while ($i < $len) {
            $ch = $body[$i];
            if (ctype_space($ch)) {
                ++$i;
                continue;
            }
            if ('/' === $ch && $i + 1 < $len && '*' === $body[$i + 1]) {
                $end = strpos($body, '*/', $i + 2);
                if (false === $end) {
                    return;
                }
                $i = $end + 2;
                continue;
            }
            break;
        }
        if ($i >= $len || '=' !== $body[$i]) {
            return;
        }
        // Distinguishes `=>` (illegal here but not this diagnostic) from default `=`.
        if ($i + 1 < $len && '>' === $body[$i + 1]) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $bodyOffsetInFile + $i),
            self::PROMOTED_HOOK_DEFAULT_AFTER_PARSE_ERROR
        );
    }

    /**
     * @return array{0: int, 1: int}|null [openBracePos, closeBracePos]
     */
    private function matchingBraceSpan(string $code, int $openPos): ?array
    {
        $depth = 0;
        $len = strlen($code);
        $inString = false;
        $stringChar = '';
        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $code[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return [$openPos, $i];
                }
            }
        }

        return null;
    }

    /**
     * Find `$prop` optionally followed by `= <expr>` then property-hook `{` (#9945, Zend/zend_compile.c).
     *
     * @return array{0: string, 1: int, 2: int}|null [prop name, `$` offset, hook `{` offset]
     */
    private function findNextPropertyHookDecl(string $body, int $offset): ?array
    {
        $len = strlen($body);
        while ($offset < $len) {
            if (!preg_match('/\$(\w+)/', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
                return null;
            }
            $prop = $m[1][0];
            $varStart = $m[0][1];
            $afterVar = $varStart + strlen($m[0][0]);
            if ($this->isOffsetInComment($body, $varStart)) {
                $offset = $afterVar + 1;
                continue;
            }
            if (
                $this->isInsideFunctionBody($body, $varStart)
                && !$this->isPromotedConstructorParamVar($body, $varStart)
            ) {
                $offset = $afterVar + 1;
                continue;
            }
            $declLookback = $varStart - 1;
            while ($declLookback >= 0 && ctype_space($body[$declLookback])) {
                --$declLookback;
            }
            // Assignment inside a condition/paren expr, not a property hook (`($dot = …) {`).
            if ($declLookback >= 0 && '(' === $body[$declLookback]) {
                $offset = $afterVar + 1;
                continue;
            }
            $i = $afterVar;
            while ($i < $len && ctype_space($body[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                return null;
            }
            if ('=' === $body[$i]) {
                // Comparisons, fat arrows, and compound assigns are not hook defaults.
                if (
                    ($i > 0 && '=' === $body[$i - 1])
                    || ($i + 1 < $len && '=' === $body[$i + 1])
                    || ($i > 0 && '>' === $body[$i - 1])
                    || ($i + 1 < $len && '>' === $body[$i + 1])
                    || ($i > 0 && '!' === $body[$i - 1])
                    || ($i > 0 && '<' === $body[$i - 1])
                ) {
                    $offset = $afterVar + 1;
                    continue;
                }
                $hookOpen = $this->scanToHookOpenBrace($body, $i + 1);
                if (null !== $hookOpen) {
                    $assignSlice = substr($body, $i + 1, $hookOpen - $i - 1);
                    if (preg_match('/\b(match|function)\b/', $assignSlice)) {
                        $offset = $afterVar + 1;
                        continue;
                    }
                    if ($this->isFunctionBodyBraceAfterParamDefault($body, $hookOpen)) {
                        $offset = $afterVar + 1;
                        continue;
                    }
                    if ($this->isForLoopHeaderBraceAfterAssignment($body, $hookOpen)) {
                        $offset = $afterVar + 1;
                        continue;
                    }

                    return [$prop, $varStart, $hookOpen];
                }
                $offset = $afterVar + 1;
                continue;
            }
            if ('{' === $body[$i]) {
                return [$prop, $varStart, $i];
            }
            $offset = $afterVar + 1;
        }

        return null;
    }


    /** Body whose comment intervals are cached in $commentIntervals (#16077). */
    private string $commentScanBody = "\0none";

    /** @var list<array{0: int, 1: int}> sorted disjoint [first, last] offsets inside comments */
    private array $commentIntervals = [];

    private function isOffsetInComment(string $body, int $offset): bool
    {
        // findNextPropertyHookDecl probes every `$var` occurrence; rescanning
        // the body from byte 0 per probe was O(vars x body) — the top lint
        // hotspot on lib/VM.php (#16077). Scan once per body, then binary
        // search. The interval builder replicates the legacy state machine's
        // exact boundary semantics (loop ran strictly below $offset).
        if ('1' === getenv('PHP_COMPILER_COMMENT_SCAN_LEGACY')) {
            return $this->isOffsetInCommentScan($body, $offset);
        }
        if ($body !== $this->commentScanBody) {
            $this->commentScanBody = $body;
            $this->commentIntervals = $this->buildCommentIntervals($body);
        }
        $lo = 0;
        $hi = \count($this->commentIntervals) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            [$first, $last] = $this->commentIntervals[$mid];
            if ($offset < $first) {
                $hi = $mid - 1;
            } elseif ($offset > $last) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function buildCommentIntervals(string $body): array
    {
        $len = strlen($body);
        $intervals = [];
        $inString = false;
        $stringChar = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $body[$i];
            $next = $i + 1 < $len ? $body[$i + 1] : '';
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('/' === $ch && '/' === $next) {
                $lineEnd = strpos($body, "\n", $i);
                if (false === $lineEnd) {
                    $lineEnd = $len;
                }
                // Legacy: true for $offset with $i < $offset < $lineEnd.
                if ($i + 1 <= $lineEnd - 1) {
                    $intervals[] = [$i + 1, $lineEnd - 1];
                }
                $i = $lineEnd;
                continue;
            }
            if ('/' === $ch && '*' === $next) {
                $start = $i;
                $end = strpos($body, '*/', $i + 2);
                if (false === $end) {
                    // Unterminated: legacy stays in-block to end of body.
                    $intervals[] = [$start + 1, $len];
                    break;
                }
                // Legacy: true for $start < $offset <= position of '*' in '*/'.
                $intervals[] = [$start + 1, $end];
                $i = $end + 1;
            }
        }

        return $intervals;
    }

    /** Legacy per-offset scan, selectable via PHP_COMPILER_COMMENT_SCAN_LEGACY=1. */
    private function isOffsetInCommentScan(string $body, int $offset): bool
    {
        $len = strlen($body);
        $inString = false;
        $stringChar = '';
        $inBlockComment = false;
        for ($i = 0; $i < $offset && $i < $len; ++$i) {
            $ch = $body[$i];
            $next = $i + 1 < $len ? $body[$i + 1] : '';
            if ($inBlockComment) {
                if ('*' === $ch && '/' === $next) {
                    $inBlockComment = false;
                    ++$i;
                }
                continue;
            }
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('/' === $ch && '/' === $next) {
                $lineEnd = strpos($body, "\n", $i);
                if (false === $lineEnd) {
                    $lineEnd = $len;
                }
                if ($offset < $lineEnd) {
                    return true;
                }
                $i = $lineEnd;
                continue;
            }
            if ('/' === $ch && '*' === $next) {
                $inBlockComment = true;
                ++$i;
            }
        }

        return $inBlockComment;
    }

    /**
     * For-loop increment clauses look like `$handler = $handler->parent) {` (#1492 bootstrap M4).
     */
    private function isForLoopHeaderBraceAfterAssignment(string $body, int $hookOpenPos): bool
    {
        if ($hookOpenPos <= 0 || '{' !== $body[$hookOpenPos]) {
            return false;
        }
        $i = $hookOpenPos - 1;
        while ($i >= 0 && ctype_space($body[$i])) {
            --$i;
        }
        if ($i < 0 || ')' !== $body[$i]) {
            return false;
        }
        $depth = 0;
        for ($j = $i; $j >= 0; --$j) {
            $ch = $body[$j];
            if (')' === $ch) {
                ++$depth;
            } elseif ('(' === $ch) {
                --$depth;
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $j));

                    return (bool) preg_match('/\bfor\s*$/s', $before);
                }
            }
        }

        return false;
    }

    /**
     * Param defaults before a function/closure body look like `$x = 1) {` or `$x = 's'): string {` (#9729, bootstrap spine).
     */
    private function isFunctionBodyBraceAfterParamDefault(string $body, int $hookOpenPos): bool
    {
        if ($hookOpenPos <= 0 || '{' !== $body[$hookOpenPos]) {
            return false;
        }
        $i = $hookOpenPos - 1;
        while ($i >= 0 && ctype_space($body[$i])) {
            --$i;
        }
        if ($i < 0) {
            return false;
        }
        if (')' !== $body[$i]) {
            while ($i >= 0 && ':' !== $body[$i]) {
                if (!preg_match('/[a-zA-Z0-9_\\\\|&?<>,()\\s]/', $body[$i])) {
                    return false;
                }
                --$i;
            }
            if ($i < 0 || ':' !== $body[$i]) {
                return false;
            }
            --$i;
            while ($i >= 0 && ctype_space($body[$i])) {
                --$i;
            }
            if ($i < 0 || ')' !== $body[$i]) {
                return false;
            }
        }
        $depth = 0;
        for ($j = $i; $j >= 0; --$j) {
            $ch = $body[$j];
            if (')' === $ch) {
                ++$depth;
            } elseif ('(' === $ch) {
                --$depth;
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $j));

                    return (bool) preg_match('/\bfunction\s*(?:&\s*)?[\w\\\\]*\s*$/s', $before);
                }
            }
        }

        return false;
    }

    /**
     * Scan from $start through an optional default initializer to the hook-block `{`.
     */
    private function scanToHookOpenBrace(string $body, int $start): ?int
    {
        $len = strlen($body);
        $depthParen = 0;
        $depthBrace = 0;
        $depthBracket = 0;
        $inString = false;
        $stringChar = '';
        for ($i = $start; $i < $len; ++$i) {
            if ($i + 2 < $len && '<<<' === substr($body, $i, 3)) {
                $afterHeredoc = $this->skipHeredocNowdoc($body, $i);
                if (null !== $afterHeredoc) {
                    $i = $afterHeredoc - 1;
                    continue;
                }
            }
            $ch = $body[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
            } elseif (')' === $ch && $depthParen > 0) {
                --$depthParen;
            } elseif (';' === $ch && 0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                return null;
            } elseif ('{' === $ch) {
                if (0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                    return $i;
                }
                ++$depthBrace;
            } elseif ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
            } elseif ('[' === $ch) {
                ++$depthBracket;
            } elseif (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
            }
        }

        return null;
    }

    /** Body whose function-body intervals are cached in $functionBodyIntervals (#23056). */
    private string $functionBodyScanBody = "\0none";

    /** @var list<array{0: int, 1: int}> sorted disjoint [first, last] offsets whose innermost brace opens a function */
    private array $functionBodyIntervals = [];

    /**
     * Local `$var = …` inside a method body is not a hooked property decl (#1492 bootstrap spine).
     */
    private function isInsideFunctionBody(string $body, int $offset): bool
    {
        // findNextPropertyHookDecl probes every `$var` occurrence, and the legacy answer walked
        // back to byte 0 per probe AND took substr($body, 0, $i) — O(vars x body) with an O(body)
        // copy each time. It was 32% of the gen-0 rebuild profile once the php-cfg simplifier
        // hotspot was removed (#23056). Same remedy as isOffsetInComment: scan once, binary search.
        if ('1' === getenv('PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY')) {
            return $this->isInsideFunctionBodyScan($body, $offset);
        }
        if ($body !== $this->functionBodyScanBody) {
            $this->functionBodyScanBody = $body;
            $this->functionBodyIntervals = $this->buildFunctionBodyIntervals($body);
        }
        $lo = 0;
        $hi = \count($this->functionBodyIntervals) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            [$first, $last] = $this->functionBodyIntervals[$mid];
            if ($offset < $first) {
                $hi = $mid - 1;
            } elseif ($offset > $last) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Offsets whose innermost enclosing `{` opens a function body.
     *
     * Mirrors the legacy backward scan exactly: for offset o that scan inspects every brace at a
     * position strictly below o, so the state established by the brace at p governs offsets
     * [p + 1, nextBracePos]. Raw byte scan, matching the legacy loop — braces inside strings and
     * comments confuse both equally, so behaviour is preserved rather than improved.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function buildFunctionBodyIntervals(string $body): array
    {
        $len = \strlen($body);
        $intervals = [];
        /** @var list<bool> $stack innermost-last: does this brace open a function body? */
        $stack = [];
        $spanStart = null;

        for ($i = 0; $i < $len; ++$i) {
            $ch = $body[$i];
            if ('{' !== $ch && '}' !== $ch) {
                continue;
            }
            // Close the span the previous state governed: [spanStart, $i].
            if (null !== $spanStart) {
                $intervals[] = [$spanStart, $i];
                $spanStart = null;
            }
            if ('{' === $ch) {
                $before = rtrim(substr($body, 0, $i));
                $stack[] = (bool) preg_match(
                    '/\bfunction\s*(?:&\s*)?[\w\\\\]*\s*\([^)]*\)\s*(?::\s*[^ {]+)?\s*$/s',
                    $before
                );
            } else {
                array_pop($stack);
            }
            if ([] !== $stack && true === $stack[\count($stack) - 1]) {
                $spanStart = $i + 1;
            }
        }
        if (null !== $spanStart && $spanStart <= $len) {
            $intervals[] = [$spanStart, $len];
        }

        // Adjacent spans coalesce; keeps the binary search shallow on brace-dense bodies.
        $merged = [];
        foreach ($intervals as [$first, $last]) {
            if ($first > $last) {
                continue;
            }
            $n = \count($merged);
            if ($n > 0 && $first <= $merged[$n - 1][1] + 1) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $last);

                continue;
            }
            $merged[] = [$first, $last];
        }

        return $merged;
    }

    /** Legacy per-offset backward scan, selectable via PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY=1. */
    private function isInsideFunctionBodyScan(string $body, int $offset): bool
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; --$i) {
            $ch = $body[$i];
            if ('}' === $ch) {
                ++$depth;
            } elseif ('{' === $ch) {
                if (0 === $depth) {
                    $before = rtrim(substr($body, 0, $i));

                    return (bool) preg_match(
                        '/\bfunction\s*(?:&\s*)?[\w\\\\]*\s*\([^)]*\)\s*(?::\s*[^ {]+)?\s*$/s',
                        $before
                    );
                }
                --$depth;
            }
        }

        return false;
    }

    private function isPromotedConstructorParamVar(string $body, int $varStart): bool
    {
        $prefix = substr($body, 0, $varStart);
        if (!preg_match('/\bfunction\s+__construct\s*\(/s', $prefix)) {
            return false;
        }
        $lineStart = strrpos(substr($body, 0, $varStart), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $linePrefix = substr($body, $lineStart, $varStart - $lineStart);

        return (bool) preg_match('/\b(public|protected|private)\b/', $linePrefix);
    }

    /**
     * @return int|null position after closing heredoc/nowdoc delimiter line
     */
    private function skipHeredocNowdoc(string $body, int $pos): ?int
    {
        $len = strlen($body);
        if ($pos + 3 > $len || '<<<' !== substr($body, $pos, 3)) {
            return null;
        }
        $i = $pos + 3;
        if ($i >= $len) {
            return null;
        }
        $label = '';
        if ("'" === $body[$i] || '"' === $body[$i]) {
            $quote = $body[$i];
            ++$i;
            while ($i < $len && (ctype_alnum($body[$i]) || '_' === $body[$i])) {
                $label .= $body[$i];
                ++$i;
            }
            if ($i >= $len || $body[$i] !== $quote) {
                return null;
            }
            ++$i;
        } else {
            while ($i < $len && (ctype_alnum($body[$i]) || '_' === $body[$i])) {
                $label .= $body[$i];
                ++$i;
            }
        }
        if ('' === $label) {
            return null;
        }
        while ($i < $len && (' ' === $body[$i] || "\t" === $body[$i])) {
            ++$i;
        }
        if ($i < $len && "\r" === $body[$i]) {
            ++$i;
        }
        if ($i < $len && "\n" === $body[$i]) {
            ++$i;
        }
        while ($i < $len) {
            $lineStart = $i;
            while ($i < $len && "\n" !== $body[$i] && "\r" !== $body[$i]) {
                ++$i;
            }
            $line = substr($body, $lineStart, $i - $lineStart);
            $stripped = rtrim($line, "\r");
            if (preg_match('/^(\s*)('.preg_quote($label, '/').')(\s*;)?\s*$/', $stripped, $m)
                && ('' === $m[1] || ctype_space($m[1]))) {
                if ($i < $len && "\r" === $body[$i]) {
                    ++$i;
                }
                if ($i < $len && "\n" === $body[$i]) {
                    ++$i;
                }

                return $i;
            }
            if ($i < $len) {
                if ("\r" === $body[$i]) {
                    ++$i;
                }
                if ($i < $len && "\n" === $body[$i]) {
                    ++$i;
                }
            }
        }

        return null;
    }

    private function processClassBody(
        string $body,
        string $lcClass,
        string $classDisplay,
        string $filename,
        int $bodyOffsetInFile,
        string $fullCode,
        string $declKind = 'class',
        bool $isAbstractClass = false
    ): string {
        $isConcreteClass = 'class' === $declKind && !$isAbstractClass;
        $injections = [];
        /** @var list<array{0: int, 1: int}> */
        $removeSpans = [];
        $offset = 0;
        $out = '';
        while (null !== ($hookDecl = $this->findNextPropertyHookDecl($body, $offset))) {
            [$prop, $declStart, $hookOpen] = $hookDecl;
            $span = $this->matchingBraceSpan($body, $hookOpen);
            if (null === $span) {
                $out .= substr($body, $offset);
                break;
            }
            [$open, $close] = $span;
            $hookSource = substr($body, $open + 1, $close - $open - 1);
            $declPrefix = $this->copyBodySegment($body, $offset, $declStart, $removeSpans);
            $propDeclHead = rtrim(substr($body, $declStart, $hookOpen - $declStart));
            // findNextPropertyHookDecl returns `$name` offset — modifiers/type sit in $declPrefix.
            // Prior members also live in $declPrefix; only this property's suffix is authoritative (#23069).
            [$priorMembers, $ownDeclPrefix] = $this->splitPropertyDeclPrefix($declPrefix);
            $ownDeclHead = $ownDeclPrefix.$propDeclHead;
            $isAbstractHook = (bool) preg_match('/\babstract\b/', $ownDeclHead);
            $isFinalProperty = (bool) preg_match('/\bfinal\b/', $ownDeclHead);
            // PHP 8.4 explicit `virtual` modifier — strip before nikic/php-parser (#18170, zend_language_parser.y).
            $isExplicitVirtual = (bool) preg_match('/\bvirtual\b/', $ownDeclHead);
            $isInterfaceHook = 'interface' === $declKind;
            // php-src zend_add_member_modifier — final+abstract on property (#29424, GH-17916).
            if ($isAbstractHook && $isFinalProperty) {
                throw new CompileFatal(
                    $filename,
                    self::lineAtOffset($fullCode, $bodyOffsetInFile + $declStart),
                    self::FINAL_ABSTRACT_PROPERTY_COMPILE_ERROR
                );
            }
            // php-src zend_add_member_modifier — final+private read visibility (#29425).
            // Strip asymmetric `*(set)` first so `final public private(set)` stays legal.
            $headSansAsymSet = preg_replace(
                '/\b(?:public|protected|private)\s*\(\s*set\s*\)/i',
                '',
                $ownDeclHead
            ) ?? $ownDeclHead;
            $isPrivateProperty = (bool) preg_match('/\bprivate\b/', $headSansAsymSet);
            if ($isFinalProperty && $isPrivateProperty) {
                throw new CompileFatal(
                    $filename,
                    self::lineAtOffset($fullCode, $bodyOffsetInFile + $declStart),
                    self::FINAL_PRIVATE_PROPERTY_COMPILE_ERROR
                );
            }
            if ($isAbstractHook) {
                $ownDeclPrefix = preg_replace('/\babstract\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                $propDeclHead = preg_replace('/\babstract\s+/', '', $propDeclHead) ?? $propDeclHead;
            }
            if ($isFinalProperty) {
                $ownDeclPrefix = preg_replace('/\bfinal\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                $propDeclHead = preg_replace('/\bfinal\s+/', '', $propDeclHead) ?? $propDeclHead;
            }
            if ($isExplicitVirtual) {
                $ownDeclPrefix = preg_replace('/\bvirtual\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                $propDeclHead = preg_replace('/\bvirtual\s+/', '', $propDeclHead) ?? $propDeclHead;
            }
            $declPrefix = $priorMembers.$ownDeclPrefix;
            $ownDeclHead = $ownDeclPrefix.$propDeclHead;
            $isStatic = (bool) preg_match('/\bstatic\b/', $ownDeclHead);
            if ($isStatic && CompilerVersion::supportsPropertyHooks()) {
                throw new CompileFatal(
                    $filename,
                    self::lineAtOffset($fullCode, $bodyOffsetInFile + $hookOpen),
                    self::STATIC_HOOK_COMPILE_ERROR
                );
            }
            $isPromotedCtorParam = $this->isPromotedConstructorParam(
                $body,
                $declStart,
                $close,
                $declPrefix,
                $propDeclHead
            );
            if ($isPromotedCtorParam) {
                $this->rejectPromotedCtorHookDefaultAfter(
                    $body,
                    $close,
                    $filename,
                    $fullCode,
                    $bodyOffsetInFile
                );
            }
            $propDecl = preg_replace('/\s+$/', '', $propDeclHead) ?? $propDeclHead;
            if (!$isPromotedCtorParam && !str_ends_with($propDecl, ';')) {
                $propDecl .= ';';
            }
            $isTraitDecl = 'trait' === $declKind;
            $skipSemicolonRequiredHooks = $isConcreteClass
                && $this->isImplicitAsymmetricBackingHookSource($hookSource);
            $propertyType = $this->propertyTypeFromDeclHead($ownDeclHead);
            [$methods, $usesBacking, $trailing, $asymmetricSetVis] = $this->lowerHooks(
                $hookSource,
                $prop,
                $lcClass,
                $isStatic,
                $skipSemicolonRequiredHooks,
                $propertyType,
                $filename,
                $fullCode,
                $bodyOffsetInFile + $hookOpen,
                $classDisplay
            );
            $this->rejectAsymmetricDeclSetWithoutSetHook(
                $ownDeclHead,
                $hookSource,
                $lcClass,
                $prop,
                $filename,
                $fullCode,
                $bodyOffsetInFile + $declStart
            );
            if (null !== $asymmetricSetVis) {
                $marker = '/*phpc-asymmetric-set:'.$asymmetricSetVis.'*/ ';
                if (preg_match('/\b(public|protected|private)\b/i', $ownDeclHead)) {
                    $marker .= '/*phpc-asymmetric-explicit-read*/ ';
                }
                if (preg_match('/^(\s*)/', $ownDeclPrefix, $indentM)) {
                    $ownDeclPrefix = $indentM[1].$marker.ltrim($ownDeclPrefix);
                } else {
                    $ownDeclPrefix = $marker.$ownDeclPrefix;
                }
                $declPrefix = $priorMembers.$ownDeclPrefix;
            }
            $sameNameBacking = $usesBacking && $this->hookTouchesBacking($hookSource, $prop, $isStatic);
            $nextOffset = $close + 1;
            $initializer = '';
            $hasInlineInitializer = $this->propertyDeclHeadHasInlineInitializer($propDeclHead);
            if ($sameNameBacking) {
                if (!$hasInlineInitializer) {
                    $backingDecl = $this->consumeSameNameBackingFieldDecl($body, $nextOffset, $prop);
                    if (null !== $backingDecl) {
                        [$nextOffset, $initializer] = $backingDecl;
                    } else {
                        $detachedBacking = $this->findDetachedSameNameBackingFieldDecl($body, $nextOffset, $prop);
                        if (null !== $detachedBacking) {
                            [$detachedStart, $detachedEnd, $initializer] = $detachedBacking;
                            $removeSpans[] = [$detachedStart, $detachedEnd];
                        } else {
                            $priorBacking = $this->findPriorSameNameBackingFieldDecl($body, $declStart, $prop);
                            if (null !== $priorBacking) {
                                [$priorStart, $priorEnd, $initializer] = $priorBacking;
                                $removeSpans[] = [$priorStart, $priorEnd];
                                $declPrefix = $this->copyBodySegment($body, $offset, $declStart, $removeSpans);
                                // Re-apply modifier strips to this property only (#23069).
                                [$priorMembers, $ownDeclPrefix] = $this->splitPropertyDeclPrefix($declPrefix);
                                if ($isAbstractHook) {
                                    $ownDeclPrefix = preg_replace('/\babstract\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                                }
                                if ($isFinalProperty) {
                                    $ownDeclPrefix = preg_replace('/\bfinal\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                                }
                                if ($isExplicitVirtual) {
                                    $ownDeclPrefix = preg_replace('/\bvirtual\s+/', '', $ownDeclPrefix) ?? $ownDeclPrefix;
                                }
                                $declPrefix = $priorMembers.$ownDeclPrefix;
                            }
                        }
                    }
                }
                $mergedDecl = rtrim($propDeclHead);
                if ('' !== $initializer) {
                    $mergedDecl .= ' '.$initializer;
                }
                if (!$isPromotedCtorParam && !str_ends_with($mergedDecl, ';')) {
                    $mergedDecl .= ';';
                }
                $out .= $declPrefix.$mergedDecl;
            } else {
                $out .= $declPrefix.$propDecl;
            }
            $trailing = trim($trailing);
            if ('' !== $trailing) {
                $out .= "\n    ".$trailing;
            }
            $isTraitAbstractHook = $isTraitDecl && [] === $methods;
            $propMeta = $this->registry[$lcClass][$prop] ?? [];
            $hasSemicolonRequirements = !empty($propMeta['requiresGet'])
                || !empty($propMeta['requiresSet'])
                || !empty($propMeta['requiresUnset']);
            $isSemicolonOnlyHook = [] === $methods && $hasSemicolonRequirements;
            if ([] !== $methods || $isAbstractHook || $isInterfaceHook || $isTraitAbstractHook || $isSemicolonOnlyHook || $isExplicitVirtual) {
                if (!isset($this->registry[$lcClass][$prop])) {
                    $this->registry[$lcClass][$prop] = [];
                }
                if ($isAbstractHook || $isInterfaceHook || $isTraitAbstractHook || $isSemicolonOnlyHook) {
                    $this->registry[$lcClass][$prop]['abstract'] = true;
                }
                // php-src ZEND_ACC_VIRTUAL — no backing store only (zend_compile.c / isVirtual()).
                // Short `set => expr` and same-name `$this->prop` imply backing (#23881); those must
                // remain non-virtual so hook raw writes succeed and get_class_vars keeps them (#22493).
                if ($isExplicitVirtual
                    || (([] !== $methods || $isInterfaceHook || $isSemicolonOnlyHook) && !$usesBacking)
                ) {
                    $this->registry[$lcClass][$prop]['virtual'] = true;
                }
                $this->rejectAsymmetricVisibilityOnPartialVirtual(
                    $ownDeclHead,
                    $lcClass,
                    $prop,
                    $classDisplay,
                    $filename,
                    $fullCode,
                    $bodyOffsetInFile + $declStart
                );
                $this->rejectBackedGetByRefWithSet(
                    $lcClass,
                    $prop,
                    $classDisplay,
                    $filename,
                    $fullCode,
                    $bodyOffsetInFile + $hookOpen
                );
            }
            if ($isFinalProperty) {
                if (!isset($this->registry[$lcClass][$prop])) {
                    $this->registry[$lcClass][$prop] = [];
                }
                $this->registry[$lcClass][$prop]['finalProperty'] = true;
            }
            $injections = array_merge($injections, $methods);
            $offset = $nextOffset;
        }
        $out .= $this->copyBodySegment($body, $offset, strlen($body), $removeSpans);
        if ([] !== $injections) {
            $out .= "\n".implode("\n", $injections)."\n";
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: bool, 2: string, 3: ?string} method source chunks, backing use, trailing decls, asymmetric set visibility
     */
    /**
     * Concrete `{ get; set (private); }` uses implicit backing field — not abstract obligations.
     *
     * Zend rejects visibility / {@code *(set)} on hooks (#29388); only the alternate
     * {@code set (vis)} marker (php-compiler) remains as an in-block asymmetric shorthand.
     */
    private function isImplicitAsymmetricBackingHookSource(string $hookSource): bool
    {
        $rest = trim($hookSource);
        if (!preg_match('/^get\s*;/', $rest)) {
            return false;
        }
        $rest = trim(preg_replace('/^get\s*;/', '', $rest, 1) ?? $rest);
        if ('' === $rest) {
            return false;
        }

        return (bool) preg_match(
            '/^set\s*\(\s*(public|protected|private)\s*\)\s*;\s*$/s',
            $rest
        );
    }

    /**
     * php-src: Zend/zend_compile.c zend_modifier_token_to_flag — only {@code final} is legal on hooks (#29388).
     */
    private function rejectIllegalHookMemberModifiers(
        string $rest,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        // No trailing \b after ")" — ";" is non-word, so \b would miss `private(set);` (#29388).
        if (preg_match('/^(public|protected|private)\s*\(\s*set\s*\)/i', $rest, $m)) {
            throw new CompileFatal(
                $filename,
                self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
                sprintf(self::HOOK_ASYMMETRIC_SET_MODIFIER_COMPILE_ERROR, strtolower($m[1]))
            );
        }
        if (preg_match('/^(public|protected|private)\s+(get|set|unset)\b/i', $rest, $m)) {
            throw new CompileFatal(
                $filename,
                self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
                sprintf(self::HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR, strtolower($m[1]))
            );
        }
    }

    private function lowerHooks(
        string $hookSource,
        string $prop,
        string $lcClass,
        bool $isStatic = false,
        bool $skipSemicolonRequiredHooks = false,
        ?string $propertyType = null,
        string $filename = 'unknown',
        string $fullCode = '',
        int $hookOpenOffsetInFile = 0,
        string $classDisplay = ''
    ): array {
        $methods = [];
        $usesBacking = false;
        $asymmetricSetVisibility = null;
        $rest = trim($hookSource);
        $lineCode = '' !== $fullCode ? $fullCode : $hookSource;
        $lineOffset = '' !== $fullCode ? $hookOpenOffsetInFile : 0;
        $classNameForError = '' !== $classDisplay ? $classDisplay : $lcClass;
        while ('' !== $rest) {
            $rest = ltrim($rest);
            // php-src: attributes precede property_hook (zend_language_parser.y, #26328).
            $hookAttrs = $this->consumeHookAttributeGroups($rest);
            $hookFinal = $this->consumeHookFinalPrefix($rest);
            // php-src: `&get` / `&set` — by-ref property hooks (zend_language_parser.y, #21098).
            $byRef = $this->consumeHookByRefPrefix($rest);
            $this->rejectIllegalHookMemberModifiers($rest, $filename, $lineCode, $lineOffset);
            if (preg_match('/^get\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresGet', $hookFinal);
                }
                if ($byRef) {
                    $this->registerGetByRefFlag($lcClass, $prop);
                }
                $rest = preg_replace('/^get\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^set\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresSet', $hookFinal);
                }
                $rest = preg_replace('/^set\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*;/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*;/i', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^unset\s*;/s', $rest)) {
                if (!$skipSemicolonRequiredHooks) {
                    $this->registerRequiredHook($lcClass, $prop, 'requiresUnset', $hookFinal);
                }
                $rest = preg_replace('/^unset\s*;/', '', $rest, 1) ?? $rest;
                continue;
            }
            if (preg_match('/^get\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^get\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
                $this->registerHookBacking($lcClass, $prop, 'get', $expr, $isStatic);
                $body = '{ return '.$expr.'; }';
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType, $byRef, false, $hookAttrs);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic, $hookFinal, $byRef);
                continue;
            }
            if (preg_match('/^get\s*\(/s', $rest)) {
                // php-src: get hook must not have a parameter list — including empty get() (#29444).
                $this->rejectGetHookParameterList(
                    $classNameForError,
                    $prop,
                    $filename,
                    $lineCode,
                    $lineOffset
                );
                // Unreachable when property hooks are enabled (throws). On reference profile, stop.
                break;
            }
            if (preg_match('/^get\s*\{/s', $rest)) {
                $rest = preg_replace('/^get\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $this->registerHookBackingFromBody($lcClass, $prop, 'get', $body, $isStatic);
                $this->registerGetHookReadBackingFromBody($lcClass, $prop, $body, $isStatic);
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType, $byRef, false, $hookAttrs);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic, $hookFinal, $byRef);
                continue;
            }
            if (preg_match('/^set\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^set\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType, $hookFinal, '$value', $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*=>\s*/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*=>\s*/i', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType, $hookFinal, '$value', $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*\(/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*/i', '', $rest, 1) ?? $rest;
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $this->rejectIncompatibleExplicitSetHookParam(
                    $params,
                    $propertyType,
                    $classNameForError,
                    $prop,
                    $filename,
                    $lineCode,
                    $lineOffset
                );
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, $params, $body, $usesBacking, $propertyType, $hookFinal, $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^set\s*\(\s*(public|protected|private)\s*\)\s*\{/s', $rest, $asymM)) {
                $asymmetricSetVisibility = strtolower($asymM[1]);
                $rest = preg_replace('/^set\s*\(\s*(public|protected|private)\s*\)\s*/i', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, '$value', $body, $usesBacking, $propertyType, $hookFinal, $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^set\s*\(/s', $rest)) {
                $rest = preg_replace('/^set\s*/', '', $rest, 1) ?? $rest;
                // php-src: Zend/zend_compile.c — `set($param) => expr` fat-arrow (#17329, PHP 8.4).
                if (preg_match('/^\(([^)]*)\)\s*=>\s*/s', $rest, $pm)) {
                    $params = trim($pm[1]);
                    $this->rejectIncompatibleExplicitSetHookParam(
                        $params,
                        $propertyType,
                        $classNameForError,
                        $prop,
                        $filename,
                        $lineCode,
                        $lineOffset
                    );
                    $rest = substr($rest, strlen($pm[0])) ?? $rest;
                    [$expr, $rest] = $this->takeUntilSemicolon($rest);
                    $methods = array_merge(
                        $methods,
                        $this->lowerSetArrowHook($lcClass, $prop, $isStatic, rtrim($expr), $usesBacking, $propertyType, $hookFinal, $params, $byRef, $hookAttrs)
                    );
                    continue;
                }
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $this->rejectIncompatibleExplicitSetHookParam(
                    $params,
                    $propertyType,
                    $classNameForError,
                    $prop,
                    $filename,
                    $lineCode,
                    $lineOffset
                );
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, $params, $body, $usesBacking, $propertyType, $hookFinal, $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^set\s*\{/s', $rest)) {
                $rest = preg_replace('/^set\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $methods = array_merge(
                    $methods,
                    $this->lowerSetBlockHook($lcClass, $prop, $isStatic, '$value', $body, $usesBacking, $propertyType, $hookFinal, $byRef, $hookAttrs)
                );
                continue;
            }
            if (preg_match('/^unset\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^unset\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $expr = rtrim($expr);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
                $body = '{ '.$expr.'; }';
                $method = self::UNSET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType, false, false, $hookAttrs);
                $this->registerHook($lcClass, $prop, 'unset', $method, $isStatic, $hookFinal);
                continue;
            }
            if (preg_match('/^unset\s*\{/s', $rest)) {
                $rest = preg_replace('/^unset\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $method = self::UNSET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body, $propertyType, false, false, $hookAttrs);
                $this->registerHook($lcClass, $prop, 'unset', $method, $isStatic, $hookFinal);
                continue;
            }
            break;
        }

        return [$methods, $usesBacking, trim($rest), $asymmetricSetVisibility];
    }

    /**
     * @return list<string>
     */
    private function lowerSetArrowHook(
        string $lcClass,
        string $prop,
        bool $isStatic,
        string $expr,
        bool &$usesBacking,
        ?string $propertyType = null,
        bool $isFinal = false,
        string $params = '$value',
        bool $byRef = false,
        string $attributes = ''
    ): array {
        if ($this->setArrowExprUsesStatementForm($expr, $isStatic)) {
            // php-src: ZEND_AST_PROPERTY_HOOK_SHORT_BODY set always counts as using the property
            // (zend_property_hook_uses_property) — even when the expr assigns a different field (#29230).
            $usesBacking = true;
            $this->registerHookBacking($lcClass, $prop, 'set', $expr, $isStatic);
            $body = '{ '.$expr.'; }';
        } else {
            $backing = $isStatic ? 'self::$'.$prop : '$this->'.$prop;
            $usesBacking = true;
            $body = '{ '.$backing.' = ('.$expr.'); }';
        }
        $method = self::SET_METHOD_PREFIX.$prop;
        $this->registerHook($lcClass, $prop, 'set', $method, $isStatic, $isFinal, $byRef);

        return [$this->hookMethodDecl($isStatic, $method, $params, $body, $propertyType, false, $byRef, $attributes)];
    }

    /**
     * @return list<string>
     */
    private function lowerGetBlockHook(
        string $lcClass,
        string $prop,
        bool $isStatic,
        string $params,
        string $body,
        bool &$usesBacking,
        ?string $propertyType = null,
        bool $isFinal = false,
        bool $byRef = false,
        string $attributes = ''
    ): array {
        $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
        $this->registerHookBackingFromBody($lcClass, $prop, 'get', $body, $isStatic);
        $this->registerGetHookReadBackingFromBody($lcClass, $prop, $body, $isStatic);
        $method = self::GET_METHOD_PREFIX.$prop;
        $this->registerHook($lcClass, $prop, 'get', $method, $isStatic, $isFinal, $byRef);
        $this->registerHookParameterizedGet($lcClass, $prop);

        return [$this->hookMethodDecl($isStatic, $method, $params, $body, $propertyType, $byRef, false, $attributes)];
    }

    private function registerHookParameterizedGet(string $lcClass, string $prop): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop]['getParameterized'] = true;
    }

    /**
     * @return list<string>
     */
    private function lowerSetBlockHook(
        string $lcClass,
        string $prop,
        bool $isStatic,
        string $params,
        string $body,
        bool &$usesBacking,
        ?string $propertyType = null,
        bool $isFinal = false,
        bool $byRef = false,
        string $attributes = ''
    ): array {
        $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
        $this->registerHookBackingFromBody($lcClass, $prop, 'set', $body, $isStatic);
        $method = self::SET_METHOD_PREFIX.$prop;
        $this->registerHook($lcClass, $prop, 'set', $method, $isStatic, $isFinal, $byRef);

        return [$this->hookMethodDecl($isStatic, $method, $params, $body, $propertyType, false, $byRef, $attributes)];
    }

    /**
     * PHP 8.4: `final get` / `final set` hook modifier (Zend/zend_compile.c, #16799).
     */
    private function consumeHookFinalPrefix(string &$rest): bool
    {
        if (!preg_match('/^final\b/s', $rest)) {
            return false;
        }
        $rest = preg_replace('/^final\s+/', '', $rest, 1) ?? $rest;

        return true;
    }

    /**
     * PHP 8.4: attribute groups before a property hook (zend_language_parser.y, #26328).
     *
     * @return string Raw `#[…]` source (possibly multiple groups) to prepend on the synthetic method
     */
    private function consumeHookAttributeGroups(string &$rest): string
    {
        $chunks = [];
        while (true) {
            $rest = ltrim($rest);
            if (!str_starts_with($rest, '#[')) {
                break;
            }
            $end = $this->findAttributeGroupEnd($rest);
            if (null === $end) {
                break;
            }
            $chunks[] = substr($rest, 0, $end + 1);
            $rest = substr($rest, $end + 1);
        }
        if ([] === $chunks) {
            return '';
        }

        return implode("\n    ", $chunks);
    }

    /** Offset of the closing `]` for a leading `#[…]` group, or null if unbalanced. */
    private function findAttributeGroupEnd(string $source): ?int
    {
        $len = strlen($source);
        if ($len < 2 || '#' !== $source[0] || '[' !== $source[1]) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $stringChar = '';
        for ($i = 1; $i < $len; ++$i) {
            $ch = $source[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('[' === $ch) {
                ++$depth;
                continue;
            }
            if (']' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * PHP 8.4: `&get` / `&set` by-ref hook prefix (zend_language_parser.y, #21098).
     */
    private function consumeHookByRefPrefix(string &$rest): bool
    {
        if (!preg_match('/^&/', $rest)) {
            return false;
        }
        $rest = preg_replace('/^&\s*/', '', $rest, 1) ?? $rest;

        return true;
    }

    private function registerGetByRefFlag(string $lcClass, string $prop): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop]['getByRef'] = true;
    }

    /**
     * True when the hooked property decl already carries `= <expr>` before the hook block (#9945, #11594).
     */
    private function propertyDeclHeadHasInlineInitializer(string $propDeclHead): bool
    {
        return (bool) preg_match('/=\s*\S/', $propDeclHead);
    }

    /**
     * When hooks read/write `$this->prop`, merge only the immediately following same-name
     * field decl (#7031).
     *
     * @return array{0: int, 1: string}|null [offset after decl, initializer including `=`]
     */
    private function consumeSameNameBackingFieldDecl(string $body, int $offset, string $prop): ?array
    {
        $remainder = substr($body, $offset);
        if (!preg_match(
            '/^\s*(?:(?:public|protected|private|static|readonly)\s+)*'
            .'(?:[\w\\\\|]+(?:\s*\[\s*\])?\s+)+'
            .'\$'.preg_quote($prop, '/').'\s*(=\s*[^;]+)?;/',
            $remainder,
            $m
        )) {
            return null;
        }

        $initializer = isset($m[1]) ? trim($m[1]) : '';

        return [$offset + strlen($m[0]), $initializer];
    }

    /**
     * Same-name backing field declared before the hooked property — merge like adjacent (#18171).
     *
     * @return array{0: int, 1: int, 2: string}|null [span start, span end, initializer including `=`]
     */
    private function findPriorSameNameBackingFieldDecl(string $body, int $searchEnd, string $prop): ?array
    {
        if ($searchEnd <= 0) {
            return null;
        }
        $remainder = substr($body, 0, $searchEnd);
        if (!preg_match_all(
            '/(?:(?:public|protected|private|static|readonly)\s+)+'
            .'(?:[\w\\\\|]+(?:\s*\[\s*\])?\s+)+'
            .'\$'.preg_quote($prop, '/').'\s*(=\s*[^;]+)?;/',
            $remainder,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }
        $last = count($matches[0]) - 1;
        if ($last < 0) {
            return null;
        }
        $matchStart = $matches[0][$last][1];
        $matchEnd = $matchStart + strlen($matches[0][$last][0]);
        $initializer = isset($matches[1][$last]) ? trim($matches[1][$last][0]) : '';

        return [$matchStart, $matchEnd, $initializer];
    }

    /**
     * Same-name backing field separated by other class members — merge like adjacent (#16936, re-#9673).
     *
     * @return array{0: int, 1: int, 2: string}|null [span start, span end, initializer including `=`]
     */
    private function findDetachedSameNameBackingFieldDecl(string $body, int $searchStart, string $prop): ?array
    {
        $remainder = substr($body, $searchStart);
        if (!preg_match(
            '/(?:(?:public|protected|private|static|readonly)\s+)+'
            .'(?:[\w\\\\|]+(?:\s*\[\s*\])?\s+)+'
            .'\$'.preg_quote($prop, '/').'\s*(=\s*[^;]+)?;/',
            $remainder,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $matchStart = $searchStart + $m[0][1];
        $matchEnd = $matchStart + strlen($m[0][0]);
        $initializer = isset($m[1]) ? trim($m[1][0]) : '';

        return [$matchStart, $matchEnd, $initializer];
    }

    /**
     * @param list<array{0: int, 1: int}> $removeSpans
     */
    private function copyBodySegment(string $body, int $from, int $to, array $removeSpans): string
    {
        if ($from >= $to) {
            return '';
        }
        if ([] === $removeSpans) {
            return substr($body, $from, $to - $from);
        }
        $result = '';
        $pos = $from;
        foreach ($removeSpans as [$start, $end]) {
            if ($end <= $from || $start >= $to) {
                continue;
            }
            $clipStart = max($start, $from);
            $clipEnd = min($end, $to);
            if ($pos < $clipStart) {
                $result .= substr($body, $pos, $clipStart - $pos);
            }
            $pos = max($pos, $clipEnd);
        }
        if ($pos < $to) {
            $result .= substr($body, $pos, $to - $pos);
        }

        return $result;
    }

    private function hookTouchesBacking(string $source, string $prop, bool $isStatic): bool
    {
        $pattern = $isStatic
            ? '/\bself::\$'.preg_quote($prop, '/').'\b/'
            : '/\$this->'.preg_quote($prop, '/').'\b/';

        return (bool) preg_match($pattern, $source);
    }

    /** Zend short set => expr: assignment statements run as-is; other exprs assign to backing (#6424). */
    private function setArrowExprUsesStatementForm(string $expr, bool $isStatic): bool
    {
        $expr = ltrim($expr);
        if (preg_match('/^\$this->\w+\s*=/', $expr)) {
            return true;
        }
        if ($isStatic && preg_match('/^self::\$\w+\s*=/', $expr)) {
            return true;
        }
        // parent::$prop::set(...) / parent::$prop->set(...) — invoke parent set hook (void), #21296
        if (preg_match('/^parent::\$\w+(?:->|::)set\s*\(/', $expr)) {
            return true;
        }

        return false;
    }

    private function hookMethodDecl(
        bool $isStatic,
        string $method,
        string $params,
        string $body,
        ?string $propertyType = null,
        bool $returnsByRef = false,
        bool $setParamByRef = false,
        string $attributes = ''
    ): string {
        $body = $this->rewriteParentPropertyHookRefCalls($body);
        $static = $isStatic ? 'static ' : '';
        $typedParams = $params;
        $returnSuffix = '';
        $amp = '';
        if ($returnsByRef && str_starts_with($method, self::GET_METHOD_PREFIX)) {
            $amp = '&';
        }
        if ($setParamByRef && str_starts_with($method, self::SET_METHOD_PREFIX)) {
            $typedParams = $this->byRefSetHookParams($typedParams);
        }
        if ($isStatic && null !== $propertyType && '' !== $propertyType) {
            if (str_starts_with($method, self::GET_METHOD_PREFIX)) {
                $returnSuffix = ': '.$propertyType;
            } elseif (str_starts_with($method, self::SET_METHOD_PREFIX)) {
                $typedParams = $this->typedSetHookParams($typedParams, $propertyType);
                $returnSuffix = ': void';
            } elseif (str_starts_with($method, self::UNSET_METHOD_PREFIX)) {
                $returnSuffix = ': void';
            }
        }
        $attrPrefix = '' !== $attributes ? $attributes."\n    " : '';
        if ('' !== $typedParams) {
            return "    {$attrPrefix}public {$static}function {$amp}{$method}({$typedParams}){$returnSuffix} {$body}";
        }

        return "    {$attrPrefix}public {$static}function {$amp}{$method}(){$returnSuffix} {$body}";
    }

    /** `&set ($value)` — receive the assigned value by reference (zend_language_parser.y). */
    private function byRefSetHookParams(string $params): string
    {
        $params = trim($params);
        if ('' === $params) {
            return '&$value';
        }
        if (preg_match('/^&/', $params)) {
            return $params;
        }
        if (preg_match('/^((?:[\w\\\\|]+\s+)*)(\$\w+)(.*)$/s', $params, $m)) {
            return $m[1].'&'.$m[2].$m[3];
        }

        return '&'.$params;
    }

    /**
     * parent::$prop->get()/set() and parent::$prop::get()/::set() → parent::__phpc_property_*()
     * for VM parent hook dispatch (#18170 arrow, #21296 colon; zend_property_hooks.c).
     */
    private function rewriteParentPropertyHookRefCalls(string $source): string
    {
        // Arrow form (legacy / early docs) and colon form (php.net / PHP 8.4).
        $rewritten = preg_replace_callback(
            '/parent::\$(\w+)(?:->|::)get\(\)/',
            fn (array $m): string => 'parent::'.self::GET_METHOD_PREFIX.$m[1].'()',
            $source
        );
        if (!is_string($rewritten)) {
            return $source;
        }
        $rewritten = preg_replace_callback(
            '/parent::\$(\w+)(?:->|::)set\(([^)]*)\)/',
            fn (array $m): string => 'parent::'.self::SET_METHOD_PREFIX.$m[1].'('.$m[2].')',
            $rewritten
        );

        return is_string($rewritten) ? $rewritten : $source;
    }

    private function typedSetHookParams(string $params, string $propertyType): string
    {
        $params = trim($params);
        if ('$value' === $params) {
            return $propertyType.' $value';
        }
        if (preg_match('/^mixed\s+\$value$/', $params)) {
            return $propertyType.' $value';
        }

        return $params;
    }

    /**
     * php-src: Zend/zend_property_hooks.c — get hook with {@code (…)} is compile-fatal (#29444).
     */
    private function rejectGetHookParameterList(
        string $classDisplay,
        string $prop,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
            self::getHookParameterListCompileError($classDisplay, $prop)
        );
    }

    public static function getHookParameterListCompileError(string $className, string $propName): string
    {
        return sprintf(self::GET_HOOK_PARAMETER_LIST_COMPILE_ERROR, $className, $propName);
    }

    /**
     * php-src: Zend/zend_compile.c — explicit set-hook param typing vs property type (#29419).
     *
     * {@code (prop_type_ast != NULL) != (value_param_ast->child[0] != NULL)} then
     * {@see zend_verify_property_hook_variance} for both-typed contravariance.
     * Order matches Zend: arity (#29443) → by-ref (#29442) → type variance.
     */
    private function rejectIncompatibleExplicitSetHookParam(
        string $params,
        ?string $propertyType,
        string $classDisplay,
        string $prop,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        $this->rejectWrongArityExplicitSetHookParam(
            $params,
            $classDisplay,
            $prop,
            $filename,
            $fullCode,
            $hookOpenOffsetInFile
        );
        $this->rejectByRefExplicitSetHookParam(
            $params,
            $classDisplay,
            $prop,
            $filename,
            $fullCode,
            $hookOpenOffsetInFile
        );
        $parsed = $this->parseExplicitSetHookParam($params);
        if (null === $parsed) {
            return;
        }
        [$paramName, $paramType] = $parsed;
        $propTyped = null !== $propertyType && '' !== trim($propertyType);
        $paramTyped = null !== $paramType && '' !== trim($paramType);
        if ($propTyped !== $paramTyped) {
            throw new CompileFatal(
                $filename,
                self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
                self::setHookValueTypeCompatError($paramName, $classDisplay, $prop)
            );
        }
        if (!$propTyped || !$paramTyped) {
            return;
        }
        if ($this->setHookParamTypeCompatibleWithProperty(trim($propertyType), trim($paramType), $classDisplay)) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
            self::setHookValueTypeCompatError($paramName, $classDisplay, $prop)
        );
    }

    /**
     * php-src: Zend/zend_compile.c — {@code param_list->children != 1} when list is present (#29443).
     */
    private function rejectWrongArityExplicitSetHookParam(
        string $params,
        string $classDisplay,
        string $prop,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        if (1 === $this->countExplicitSetHookParams($params)) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
            self::setHookArityCompileError($classDisplay, $prop)
        );
    }

    /**
     * php-src: Zend/zend_compile.c — {@code value_param_ast->attr & ZEND_PARAM_REF} (#29442).
     */
    private function rejectByRefExplicitSetHookParam(
        string $params,
        string $classDisplay,
        string $prop,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        $paramName = $this->explicitSetHookParamByRefName($params);
        if (null === $paramName) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
            self::setHookParamByRefCompileError($paramName, $classDisplay, $prop)
        );
    }

    public static function setHookValueTypeCompatError(string $paramName, string $className, string $propName): string
    {
        return sprintf(self::SET_HOOK_VALUE_TYPE_COMPAT_ERROR, $paramName, $className, $propName);
    }

    public static function setHookArityCompileError(
        string $className,
        string $propName,
        string $hookKind = 'set'
    ): string {
        return sprintf(self::SET_HOOK_ARITY_COMPILE_ERROR, $hookKind, $className, $propName);
    }

    public static function setHookParamByRefCompileError(
        string $paramName,
        string $className,
        string $propName,
        string $hookKind = 'set'
    ): string {
        return sprintf(self::SET_HOOK_PARAM_BY_REF_COMPILE_ERROR, $paramName, $hookKind, $className, $propName);
    }

    /**
     * Count top-level parameters in an explicit set-hook {@code (…)} list.
     *
     * Empty {@code set()} → 0; trailing commas do not add a parameter ({@code set($v,)} → 1).
     */
    private function countExplicitSetHookParams(string $params): int
    {
        $params = trim($params);
        if ('' === $params) {
            return 0;
        }
        $count = 0;
        $depthParen = 0;
        $depthBracket = 0;
        $depthBrace = 0;
        $len = strlen($params);
        $segStart = 0;
        $quote = null;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $params[$i];
            if (null !== $quote) {
                if ('\\' === $ch && $i + 1 < $len) {
                    ++$i;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
                continue;
            }
            if (')' === $ch && $depthParen > 0) {
                --$depthParen;
                continue;
            }
            if ('[' === $ch) {
                ++$depthBracket;
                continue;
            }
            if (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
                continue;
            }
            if ('{' === $ch) {
                ++$depthBrace;
                continue;
            }
            if ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
                continue;
            }
            if (',' === $ch && 0 === $depthParen && 0 === $depthBracket && 0 === $depthBrace) {
                if ('' !== trim(substr($params, $segStart, $i - $segStart))) {
                    ++$count;
                }
                $segStart = $i + 1;
            }
        }
        if ('' !== trim(substr($params, $segStart))) {
            ++$count;
        }

        return $count;
    }

    /**
     * Param name when the set-hook parameter is pass-by-reference ({@code &$value} / {@code T &$value}).
     *
     * Intersection types use {@code &} between names ({@code Foo&Bar $v}); only {@code &} immediately
     * before {@code $name} is by-ref (Zend {@code ZEND_PARAM_REF}).
     */
    private function explicitSetHookParamByRefName(string $params): ?string
    {
        $params = trim($params);
        while (str_starts_with($params, '#[')) {
            $end = $this->findAttributeGroupEnd($params);
            if (null === $end) {
                break;
            }
            $params = ltrim(substr($params, $end + 1));
        }
        if ('' === $params) {
            return null;
        }
        if (preg_match('/^(.+?)(\s*=\s*.+)$/s', $params, $dm) && !str_contains($dm[1], '(')) {
            if (preg_match('/\$\w+\s*$/', $dm[1])) {
                $params = rtrim($dm[1]);
            }
        }
        if (preg_match('/&\s*\$(\w+)\s*$/', $params, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}|null [paramNameWithoutDollar, type or null]
     */
    private function parseExplicitSetHookParam(string $params): ?array
    {
        $params = trim($params);
        while (str_starts_with($params, '#[')) {
            $end = $this->findAttributeGroupEnd($params);
            if (null === $end) {
                break;
            }
            $params = ltrim(substr($params, $end + 1));
        }
        if ('' === $params) {
            return null;
        }
        // Drop default (`$v = …`) — Zend rejects defaults separately; still parse the name/type.
        if (preg_match('/^(.+?)(\s*=\s*.+)$/s', $params, $dm) && !str_contains($dm[1], '(')) {
            // Only strip default when the `=` is not inside a type (DNF unlikely in hook params).
            if (preg_match('/\$\w+\s*$/', $dm[1])) {
                $params = rtrim($dm[1]);
            }
        }
        if (preg_match('/^&?\s*(\$\w+)\s*$/', $params, $m)) {
            return [substr($m[1], 1), null];
        }
        if (preg_match('/^(.+?)\s+(&\s*)?(\$\w+)\s*$/s', $params, $m)) {
            $type = trim($m[1]);
            if ('' === $type || str_starts_with($type, '$')) {
                return [substr($m[3], 1), null];
            }

            return [substr($m[3], 1), $type];
        }

        return null;
    }

    /**
     * Set-param must be the same type or contravariant (wider) than the property type.
     *
     * php-src: zend_verify_property_hook_variance → zend_perform_covariant_type_check(prop, set).
     */
    private function setHookParamTypeCompatibleWithProperty(
        string $propertyType,
        string $setParamType,
        string $classDisplay
    ): bool {
        $norm = static function (string $t): string {
            $t = preg_replace('/\s+/', '', $t) ?? $t;

            return strtolower($t);
        };
        $propN = $norm($propertyType);
        $setN = $norm($setParamType);
        if ($propN === $setN) {
            return true;
        }
        if ('mixed' === $setN) {
            return true;
        }
        // Union / DNF on the set side: property type (or its non-null core) must be accepted.
        if (str_contains($setN, '|') && !str_contains($setN, '(')) {
            $members = explode('|', $setN);
            $propCore = str_starts_with($propN, '?') ? substr($propN, 1) : $propN;
            if (in_array($propN, $members, true) || in_array($propCore, $members, true)) {
                return true;
            }
            if (str_starts_with($propN, '?') && in_array('null', $members, true) && in_array($propCore, $members, true)) {
                return true;
            }
        }
        $ownerLc = strtolower($classDisplay);
        // TypeSig lives in InheritanceVariance.php (not a separate autoload file).
        class_exists(InheritanceVariance::class);
        $propSig = \PHPCompiler\Compiler\TypeSig::fromDumpTypeString($propertyType);
        $setSig = \PHPCompiler\Compiler\TypeSig::fromDumpTypeString($setParamType);
        if (null === $propSig || null === $setSig) {
            // Unresolved / union dump forms we could not prove — only reject clear builtin mismatches below.
            $builtins = InheritanceVariance::BUILTIN_SCALARS;
            $propKey = str_starts_with($propN, '?') ? substr($propN, 1) : $propN;
            $setKey = str_starts_with($setN, '?') ? substr($setN, 1) : $setN;
            if (isset($builtins[$propKey]) && isset($builtins[$setKey]) && $propKey !== $setKey) {
                // int↔float widening is not allowed for property-hook variance (unlike arg passing).
                return false;
            }

            // Class / unresolved — defer (Zend may delay); identical-after-norm already handled.
            return !isset($builtins[$propKey]) || !isset($builtins[$setKey]);
        }
        // prop type must be a subtype of set-param type (set is wider / contravariant).
        return InheritanceVariance::isCovariantTypeCompatible(
            $setSig,
            $propSig,
            $ownerLc,
            $ownerLc,
            static fn (string $a, string $b): bool => $a === $b,
            static fn (string $a, string $b): bool => false
        );
    }

    /**
     * Split class-body text before `$prop` into prior members vs this property's modifiers/type.
     *
     * `findNextPropertyHookDecl` returns the `$name` offset, so modifiers sit in `$declPrefix`.
     * Earlier properties/methods in the same prefix must not contribute `final`/`abstract`/… (#23069).
     *
     * @return array{0: string, 1: string} [priorMembers, ownDeclPrefix]
     */
    private function splitPropertyDeclPrefix(string $declPrefix): array
    {
        $own = $this->propertyOwnDeclPrefix($declPrefix);
        $priorLen = strlen($declPrefix) - strlen($own);

        return [substr($declPrefix, 0, $priorLen), $own];
    }

    /**
     * Suffix of `$declPrefix` that belongs to the hooked property (after last `;` / `}`).
     */
    private function propertyOwnDeclPrefix(string $declPrefix): string
    {
        $len = strlen($declPrefix);
        $lastTerm = -1;
        $i = 0;
        while ($i < $len) {
            $c = $declPrefix[$i];
            if ('/' === $c && $i + 1 < $len && '/' === $declPrefix[$i + 1]) {
                $i += 2;
                while ($i < $len && "\n" !== $declPrefix[$i] && "\r" !== $declPrefix[$i]) {
                    ++$i;
                }
                continue;
            }
            if ('/' === $c && $i + 1 < $len && '*' === $declPrefix[$i + 1]) {
                $i += 2;
                while ($i + 1 < $len && !('*' === $declPrefix[$i] && '/' === $declPrefix[$i + 1])) {
                    ++$i;
                }
                $i = min($len, $i + 2);
                continue;
            }
            if ('"' === $c || "'" === $c) {
                $quote = $c;
                ++$i;
                while ($i < $len) {
                    if ('\\' === $declPrefix[$i]) {
                        $i += 2;
                        continue;
                    }
                    if ($quote === $declPrefix[$i]) {
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            if (';' === $c || '}' === $c) {
                $lastTerm = $i;
            }
            ++$i;
        }

        return substr($declPrefix, $lastTerm + 1);
    }

    private function propertyTypeFromDeclHead(string $propDeclHead): ?string
    {
        // Strip asymmetric set-visibility before type extraction (#29672).
        // Otherwise `public private(set) string $x` yields type `private(set) string`
        // and matching `set(string $v)` falsely fails the XOR/compat check.
        // Same forms as {@see rejectAsymmetricDeclSetWithoutSetHook} / final+private gate:
        // `private(set)` and parenthesized `(private(set))`.
        $s = preg_replace(
            '/\(\s*(?:public|protected|private)\s*\(\s*set\s*\)\s*\)/i',
            '',
            $propDeclHead
        ) ?? $propDeclHead;
        $s = preg_replace(
            '/\b(?:public|protected|private)\s*\(\s*set\s*\)/i',
            '',
            $s
        ) ?? $s;
        $s = preg_replace(
            '/\b(public|protected|private|static|readonly|abstract|final|virtual)\s+/',
            '',
            $s
        ) ?? $s;
        $s = trim($s);
        if (!preg_match('/^(.+?)\s+\$/', $s, $m)) {
            return null;
        }
        $type = trim($m[1]);

        return '' !== $type ? $type : null;
    }

    /**
     * @param 'requiresGet'|'requiresSet'|'requiresUnset' $flag
     */
    private function registerRequiredHook(string $lcClass, string $prop, string $flag, bool $isFinal = false): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop][$flag] = true;
        if ($isFinal) {
            $kind = 'requiresGet' === $flag ? 'get' : ('requiresSet' === $flag ? 'set' : 'unset');
            $this->registry[$lcClass][$prop]['final'.ucfirst($kind)] = true;
        }
    }

    /**
     * @param 'get'|'set'|'unset' $kind
     */
    private function registerHook(
        string $lcClass,
        string $prop,
        string $kind,
        string $method,
        bool $isStatic,
        bool $isFinal = false,
        bool $byRef = false
    ): void {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop][$kind] = $method;
        if ($isStatic) {
            $this->registry[$lcClass][$prop]['static'] = true;
        }
        if ($isFinal) {
            $this->registry[$lcClass][$prop]['final'.ucfirst($kind)] = true;
        }
        if ($byRef && 'get' === $kind) {
            $this->registry[$lcClass][$prop]['getByRef'] = true;
        }
        if ($byRef && 'set' === $kind) {
            $this->registry[$lcClass][$prop]['setByRef'] = true;
        }
    }

    /**
     * Record `$this->field` / `self::$field` read/write targets for foreach-by-ref (#6435).
     *
     * @param 'get'|'set' $kind
     */
    private function registerHookBacking(string $lcClass, string $prop, string $kind, string $expr, bool $isStatic): void
    {
        $expr = trim($expr);
        if ($isStatic) {
            if (preg_match('/^self::\$(\w+)\s*(?:=\s*|$)/', $expr, $m)) {
                // Same-name self::$prop is implicit backing, not separate setBacking (#22452).
                if ('set' === $kind && 0 === strcasecmp($m[1], $prop)) {
                    return;
                }
                $key = 'get' === $kind ? 'getBacking' : 'setBacking';
                $this->registry[$lcClass][$prop][$key] = $m[1];
            }

            return;
        }
        if ('get' === $kind && preg_match('/^\$this->(\w+)$/', $expr, $m)) {
            $this->registry[$lcClass][$prop]['getBacking'] = $m[1];

            return;
        }
        // Distinct `$this->other =` only — same-name is backed storage (#22452 / #19163).
        if ('set' === $kind && preg_match('/^\$this->(\w+)\s*=/', $expr, $m)
            && 0 !== strcasecmp($m[1], $prop)
        ) {
            $this->registry[$lcClass][$prop]['setBacking'] = $m[1];
        }
    }

    /**
     * Record separate backing field targets from hook block bodies (#6635).
     *
     * @param 'get'|'set' $kind
     */
    private function registerHookBackingFromBody(
        string $lcClass,
        string $prop,
        string $kind,
        string $body,
        bool $isStatic
    ): void {
        if ($isStatic) {
            if (preg_match('/\bself::\$(\w+)\s*=/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
                $key = 'get' === $kind ? 'getBacking' : 'setBacking';
                $this->registry[$lcClass][$prop][$key] = $m[1];
            }

            return;
        }
        if (preg_match('/\$this->(\w+)\s*=/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
            $key = 'get' === $kind ? 'getBacking' : 'setBacking';
            $this->registry[$lcClass][$prop][$key] = $m[1];
        }
    }

    /**
     * Record `$this->field` read targets from get { } bodies (#17330, #6635).
     */
    private function registerGetHookReadBackingFromBody(
        string $lcClass,
        string $prop,
        string $body,
        bool $isStatic
    ): void {
        if ($isStatic) {
            if (preg_match('/\bself::\$(\w+)\b/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
                $this->registry[$lcClass][$prop]['getBacking'] = $m[1];
            }

            return;
        }
        if (preg_match('/\$this->(\w+)\b/', $body, $m) && strcasecmp($m[1], $prop) !== 0) {
            $this->registry[$lcClass][$prop]['getBacking'] = $m[1];
        }
    }

    /**
     * @return array{0: string, 1: string} [expression/statement, remainder after ';']
     */
    private function takeUntilSemicolon(string $source): array
    {
        $source = ltrim($source);
        $len = strlen($source);
        $depthParen = 0;
        $depthBrace = 0;
        $depthBracket = 0;
        $inString = false;
        $stringChar = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $source[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
            } elseif (')' === $ch && $depthParen > 0) {
                --$depthParen;
            } elseif ('{' === $ch) {
                ++$depthBrace;
            } elseif ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
            } elseif ('[' === $ch) {
                ++$depthBracket;
            } elseif (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
            } elseif (';' === $ch && 0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                $chunk = rtrim(substr($source, 0, $i));

                return [$chunk, ltrim(substr($source, $i + 1))];
            }
        }

        return [rtrim($source), ''];
    }

    /**
     * @return array{0: string, 1: string} [brace block including braces, remainder]
     */
    private function takeBraceBody(string $source): array
    {
        $source = ltrim($source);
        if (!str_starts_with($source, '{')) {
            return ['{ }', ''];
        }
        $span = $this->matchingBraceSpan($source, 0);
        if (null === $span) {
            return ['{ }', ''];
        }
        [$open, $close] = $span;
        $block = substr($source, $open, $close - $open + 1);

        return [$block, ltrim(substr($source, $close + 1))];
    }

    public static function setHookMethodName(string $property): string
    {
        return self::SET_METHOD_PREFIX.$property;
    }

    public static function getHookMethodName(string $property): string
    {
        return self::GET_METHOD_PREFIX.$property;
    }

    public static function unsetHookMethodName(string $property): string
    {
        return self::UNSET_METHOD_PREFIX.$property;
    }

    public static function propertyNameFromSetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::SET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }

    public static function propertyNameFromGetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::GET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }

    /**
     * ReflectionMethod name for a synthetic hook (`$prop::get` / `$prop::set`) — #26328.
     *
     * {@see ReflectionPropertyHookSupport::hookReflectionMethod} uses this form; attribute
     * tables are dual-keyed under both the synthetic method and this reflection name.
     */
    public static function reflectionNameFromHookMethod(string $methodLc): ?string
    {
        $methodLc = strtolower($methodLc);
        $prop = self::propertyNameFromGetHookMethod($methodLc);
        if (null !== $prop) {
            return '$'.$prop.'::get';
        }
        $prop = self::propertyNameFromSetHookMethod($methodLc);
        if (null !== $prop) {
            return '$'.$prop.'::set';
        }

        return null;
    }

    /**
     * Zend TypeError / ArgumentCountError callable label for synthetic hook methods (#29666).
     *
     * Maps {@code Class::__phpc_property_set_x} → {@code Class::$x::set} (and get symmetrically).
     * Non-hook names are returned unchanged. Internal symbols stay for dispatch; only user-visible
     * error framing uses this form (zend_property_hooks.c).
     */
    public static function zendTypeErrorCallableName(string $qualifiedName): string
    {
        $class = '';
        $method = $qualifiedName;
        if (str_contains($qualifiedName, '::')) {
            [$class, $method] = explode('::', $qualifiedName, 2);
        }
        $hook = self::reflectionNameFromHookMethod(strtolower($method));
        if (null === $hook) {
            return $qualifiedName;
        }
        if ('' === $class) {
            return $hook;
        }

        return $class.'::'.$hook;
    }

    /**
     * Resolve `$prop::get` / `$prop::set` ReflectionMethod names to synthetic hook methods (#26328).
     */
    public static function hookMethodFromReflectionName(string $methodLc): ?string
    {
        $methodLc = strtolower($methodLc);
        if (preg_match('/^\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)::(get|set)$/', $methodLc, $m)) {
            return 'get' === $m[2]
                ? strtolower(self::getHookMethodName($m[1]))
                : strtolower(self::setHookMethodName($m[1]));
        }

        return null;
    }

    private static function lineAtOffset(string $code, int $offset): int
    {
        return substr_count(substr($code, 0, max(0, $offset)), "\n") + 1;
    }

    /**
     * php-src: Zend/zend_compile.c — asymmetric `(set)` on the property decl requires a set hook
     * (`set =>`, `set { }`, abstract `set;`, …) unless the block is get-only with an implemented get hook
     * (`get =>`, `get { }`) — PHP 8.4 (#13983, zend_property_hooks.c).
     *
     * Get-only *virtual* properties with decl-site aviz are rejected separately
     * ({@see rejectAsymmetricVisibilityOnPartialVirtual}, #29426).
     *
     * In-block {@code private set;} / {@code private(set);} are illegal on hooks (#29388).
     */
    private function rejectAsymmetricDeclSetWithoutSetHook(
        string $declHead,
        string $hookSource,
        string $lcClass,
        string $prop,
        string $filename,
        string $fullCode,
        int $declOffsetInFile
    ): void {
        if (!$this->declHeadHasAsymmetricSetVisibility($declHead)) {
            return;
        }
        $propMeta = $this->registry[$lcClass][$prop] ?? [];
        $hasSetHook = isset($propMeta['set'])
            || !empty($propMeta['requiresSet'])
            || $this->isImplicitAsymmetricBackingHookSource($hookSource);
        if ($hasSetHook) {
            return;
        }
        if (isset($propMeta['get'])) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $declOffsetInFile),
            self::ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE
        );
    }

    /**
     * php-src: Zend/zend_inheritance.c zend_verify_hooked_property (#29426).
     *
     * {@code ZEND_ACC_VIRTUAL} + {@code ZEND_ACC_PPP_SET_MASK} requires both get and set hooks.
     */
    private function rejectAsymmetricVisibilityOnPartialVirtual(
        string $declHead,
        string $lcClass,
        string $prop,
        string $classDisplayName,
        string $filename,
        string $fullCode,
        int $declOffsetInFile
    ): void {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        if (!$this->declHeadHasAsymmetricSetVisibility($declHead)) {
            return;
        }
        $propMeta = $this->registry[$lcClass][$prop] ?? [];
        if (empty($propMeta['virtual'])) {
            return;
        }
        $hasGet = isset($propMeta['get']) || !empty($propMeta['requiresGet']);
        $hasSet = isset($propMeta['set']) || !empty($propMeta['requiresSet']);
        if ($hasGet && $hasSet) {
            return;
        }
        $message = !$hasGet
            ? sprintf(self::WRITEONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR, $classDisplayName, $prop)
            : sprintf(self::READONLY_VIRTUAL_ASYMMETRIC_VISIBILITY_COMPILE_ERROR, $classDisplayName, $prop);
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $declOffsetInFile),
            $message
        );
    }

    /**
     * php-src zend_verify_hooked_property — backed `&get` cannot coexist with `set` (#29230).
     *
     * Virtual properties may declare both (zend_inheritance.c); arrow `set =>` is never virtual.
     */
    private function rejectBackedGetByRefWithSet(
        string $lcClass,
        string $prop,
        string $classDisplayName,
        string $filename,
        string $fullCode,
        int $hookOpenOffsetInFile
    ): void {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        $propMeta = $this->registry[$lcClass][$prop] ?? [];
        if (empty($propMeta['getByRef'])) {
            return;
        }
        $hasSet = isset($propMeta['set']) || !empty($propMeta['requiresSet']);
        if (!$hasSet) {
            return;
        }
        if (!empty($propMeta['virtual'])) {
            return;
        }
        throw new CompileFatal(
            $filename,
            self::lineAtOffset($fullCode, $hookOpenOffsetInFile),
            self::backedGetByRefWithSetCompileError($classDisplayName, $prop)
        );
    }

    private function declHeadHasAsymmetricSetVisibility(string $declHead): bool
    {
        return (bool) preg_match('/\b(public|protected|private)\s*\(\s*set\s*\)/i', $declHead);
    }
}
