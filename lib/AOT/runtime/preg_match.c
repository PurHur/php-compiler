/*
 * preg_* runtime for AOT/JIT bootstrap (issue #93, #4874).
 *
 * Uses libpcre2-8 when pcre2.h is available; otherwise conservative stubs that
 * report PHPC_PREG_BAD_REGEX (bootstrap envs without dev headers).
 */

#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>
#include <ctype.h>
#include <string.h>

#if defined(__has_include)
#if __has_include(<pcre2.h>)
#define PHPC_HAVE_PCRE2 1
#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>
#endif
#endif

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

/* PHP PREG_* codes (subset; see ext/pcre/php_pcre.c). */
#define PHPC_PREG_NO_ERROR 0
#define PHPC_PREG_INTERNAL_ERROR 1
#define PHPC_PREG_BACKTRACK_LIMIT_ERROR 2
#define PHPC_PREG_RECURSION_LIMIT_ERROR 3
#define PHPC_PREG_BAD_UTF8_ERROR 4
#define PHPC_PREG_BAD_UTF8_OFFSET_ERROR 5
#define PHPC_PREG_BAD_REGEX 6
#define PHPC_PREG_JIT_STACKLIMIT_ERROR 7

static int phpc_preg_last_error = PHPC_PREG_NO_ERROR;

extern __string__ *__string__init(long long size, const char *value);

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static const char *phpc_preg_error_msg(int code)
{
    switch (code) {
    case PHPC_PREG_NO_ERROR:
        return "No error";
    case PHPC_PREG_INTERNAL_ERROR:
        return "Internal error";
    case PHPC_PREG_BAD_UTF8_ERROR:
        return "Malformed UTF-8 characters, possibly incorrectly encoded";
    case PHPC_PREG_BAD_UTF8_OFFSET_ERROR:
        return "The offset did not correspond to the beginning of a valid UTF-8 code point";
    case PHPC_PREG_BACKTRACK_LIMIT_ERROR:
        return "Backtrack limit exhausted";
    case PHPC_PREG_RECURSION_LIMIT_ERROR:
        return "Recursion limit exhausted";
    case PHPC_PREG_JIT_STACKLIMIT_ERROR:
        return "JIT stack limit exhausted";
    default:
        return "Unknown error";
    }
}

static void phpc_preg_set_error(int code)
{
    phpc_preg_last_error = code;
}

int64_t __compiler_preg_last_error(void)
{
    return (int64_t) phpc_preg_last_error;
}

__string__ *__compiler_preg_last_error_msg(void)
{
    const char *msg = phpc_preg_error_msg(phpc_preg_last_error);
    size_t len = strlen(msg);

    return __string__init((long long) len, msg);
}

#ifdef PHPC_HAVE_PCRE2

static int phpc_is_valid_delimiter(char c)
{
    if (c == '\0' || c == '\\') {
        return 0;
    }
    if (isalnum((unsigned char) c)) {
        return 0;
    }

    return 1;
}

static int phpc_parse_php_pattern(const char *pattern, size_t pattern_len, char **regex, size_t *regex_len, uint32_t *opts)
{
    const char *p;
    const char *end;
    char delimiter;

    if (NULL == pattern || pattern_len < 2) {
        return 0;
    }

    delimiter = pattern[0];
    if (!phpc_is_valid_delimiter(delimiter)) {
        return 0;
    }

    p = pattern + 1;
    end = pattern + pattern_len;
    while (p < end) {
        if (*p == '\\') {
            if (p + 1 < end) {
                p += 2;
                continue;
            }

            return 0;
        }
        if (*p == delimiter) {
            break;
        }
        p++;
    }
    if (p >= end) {
        return 0;
    }

    *regex_len = (size_t) (p - (pattern + 1));
    *regex = (char *) malloc(*regex_len + 1);
    if (NULL == *regex) {
        return 0;
    }
    if (*regex_len > 0) {
        memcpy(*regex, pattern + 1, *regex_len);
    }
    (*regex)[*regex_len] = '\0';

    *opts = 0;
    p++;
    while (p < end) {
        switch (*p) {
        case 'i':
            *opts |= PCRE2_CASELESS;
            break;
        case 'm':
            *opts |= PCRE2_MULTILINE;
            break;
        case 's':
            *opts |= PCRE2_DOTALL;
            break;
        case 'x':
            *opts |= PCRE2_EXTENDED;
            break;
        case 'A':
            *opts |= PCRE2_ANCHORED;
            break;
        case 'D':
            *opts |= PCRE2_DOLLAR_ENDONLY;
            break;
        case 'U':
            *opts |= PCRE2_UNGREEDY;
            break;
        case 'u':
            *opts |= PCRE2_UTF;
            break;
        default:
            free(*regex);
            *regex = NULL;
            return 0;
        }
        p++;
    }

    return 1;
}

static int phpc_pcre2_error_to_preg(int errorcode)
{
    switch (errorcode) {
    case 0:
        return PHPC_PREG_NO_ERROR;
    case PCRE2_ERROR_UTF8_ERR1:
    case PCRE2_ERROR_UTF8_ERR2:
    case PCRE2_ERROR_UTF8_ERR3:
    case PCRE2_ERROR_UTF8_ERR4:
    case PCRE2_ERROR_UTF8_ERR5:
    case PCRE2_ERROR_UTF8_ERR6:
    case PCRE2_ERROR_UTF8_ERR7:
    case PCRE2_ERROR_UTF8_ERR8:
    case PCRE2_ERROR_UTF8_ERR9:
    case PCRE2_ERROR_UTF8_ERR10:
    case PCRE2_ERROR_UTF8_ERR11:
    case PCRE2_ERROR_UTF8_ERR12:
    case PCRE2_ERROR_UTF8_ERR13:
    case PCRE2_ERROR_UTF8_ERR14:
    case PCRE2_ERROR_UTF8_ERR15:
    case PCRE2_ERROR_UTF8_ERR16:
    case PCRE2_ERROR_UTF8_ERR17:
    case PCRE2_ERROR_UTF8_ERR18:
    case PCRE2_ERROR_UTF8_ERR19:
    case PCRE2_ERROR_UTF8_ERR20:
    case PCRE2_ERROR_UTF8_ERR21:
        return PHPC_PREG_BAD_UTF8_ERROR;
    case PCRE2_ERROR_BADDATA:
    case PCRE2_ERROR_BADUTFOFFSET:
        return PHPC_PREG_BAD_UTF8_OFFSET_ERROR;
    case PCRE2_ERROR_MATCHLIMIT:
        return PHPC_PREG_BACKTRACK_LIMIT_ERROR;
    case PCRE2_ERROR_DEPTHLIMIT:
        return PHPC_PREG_RECURSION_LIMIT_ERROR;
    case PCRE2_ERROR_JIT_STACKLIMIT:
        return PHPC_PREG_JIT_STACKLIMIT_ERROR;
    default:
        return PHPC_PREG_BAD_REGEX;
    }
}

static pcre2_code_8 *phpc_compile(__string__ *pattern, int *preg_error)
{
    char *regex = NULL;
    size_t regex_len = 0;
    uint32_t opts = 0;
    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code_8 *code;
    const char *pat = phpc_strdata(pattern);
    size_t pat_len = phpc_strlen(pattern);

    *preg_error = PHPC_PREG_NO_ERROR;
    if (!phpc_parse_php_pattern(pat, pat_len, &regex, &regex_len, &opts)) {
        *preg_error = PHPC_PREG_BAD_REGEX;

        return NULL;
    }

    code = pcre2_compile_8((PCRE2_SPTR8) regex, regex_len, opts, &errorcode, &erroffset, NULL);
    free(regex);
    if (NULL == code) {
        *preg_error = phpc_pcre2_error_to_preg(errorcode);

        return NULL;
    }

    return code;
}

static int64_t phpc_preg_match_internal(__string__ *pattern, __string__ *subject)
{
    pcre2_code_8 *code;
    pcre2_match_data_8 *match_data;
    int preg_error = PHPC_PREG_NO_ERROR;
    int rc;
    const char *subj = phpc_strdata(subject);
    size_t subj_len = phpc_strlen(subject);

    code = phpc_compile(pattern, &preg_error);
    if (NULL == code) {
        phpc_preg_set_error(preg_error);

        return -1;
    }

    match_data = pcre2_match_data_create_from_pattern_8(code, NULL);
    if (NULL == match_data) {
        pcre2_code_free_8(code);
        phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

        return -1;
    }

    rc = pcre2_match_8(code, (PCRE2_SPTR8) subj, subj_len, 0, 0, match_data, NULL);
    pcre2_match_data_free_8(match_data);
    pcre2_code_free_8(code);

    if (-1 == rc) {
        phpc_preg_set_error(PHPC_PREG_NO_ERROR);

        return 0;
    }
    if (rc < 0) {
        phpc_preg_set_error(phpc_pcre2_error_to_preg(rc));

        return -1;
    }

    phpc_preg_set_error(PHPC_PREG_NO_ERROR);

    return (int64_t) (rc > 0 ? 1 : 0);
}

static __string__ *phpc_preg_replace_internal(__string__ *pattern, __string__ *replacement, __string__ *subject)
{
    pcre2_code_8 *code;
    pcre2_match_data_8 *match_data;
    int preg_error = PHPC_PREG_NO_ERROR;
    int rc;
    const char *subj = phpc_strdata(subject);
    size_t subj_len = phpc_strlen(subject);
    const char *repl = phpc_strdata(replacement);
    size_t repl_len = phpc_strlen(replacement);
    PCRE2_SIZE offset = 0;
    char *buf = NULL;
    size_t buf_len = 0;
    size_t buf_cap = 0;
    __string__ *result;

    code = phpc_compile(pattern, &preg_error);
    if (NULL == code) {
        phpc_preg_set_error(preg_error);

        return NULL;
    }

    match_data = pcre2_match_data_create_from_pattern_8(code, NULL);
    if (NULL == match_data) {
        pcre2_code_free_8(code);
        phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

        return NULL;
    }

    while (offset <= subj_len) {
        rc = pcre2_match_8(code, (PCRE2_SPTR8) subj, subj_len, offset, 0, match_data, NULL);
        if (-1 == rc) {
            size_t tail = subj_len - offset;
            if (tail > 0) {
                char *next = (char *) realloc(buf, buf_len + tail + 1);
                if (NULL == next) {
                    free(buf);
                    pcre2_match_data_free_8(match_data);
                    pcre2_code_free_8(code);
                    phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

                    return NULL;
                }
                buf = next;
                memcpy(buf + buf_len, subj + offset, tail);
                buf_len += tail;
            }
            break;
        }
        if (rc < 0) {
            free(buf);
            pcre2_match_data_free_8(match_data);
            pcre2_code_free_8(code);
            phpc_preg_set_error(phpc_pcre2_error_to_preg(rc));

            return NULL;
        }
        {
            PCRE2_SIZE *ovector = pcre2_get_ovector_pointer_8(match_data);
            PCRE2_SIZE start = ovector[0];
            PCRE2_SIZE end = ovector[1];
            size_t prefix = (size_t) start - offset;
            size_t need = buf_len + prefix + repl_len;
            if (need + 1 > buf_cap) {
                size_t new_cap = need + 64;
                char *next = (char *) realloc(buf, new_cap);
                if (NULL == next) {
                    free(buf);
                    pcre2_match_data_free_8(match_data);
                    pcre2_code_free_8(code);
                    phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

                    return NULL;
                }
                buf = next;
                buf_cap = new_cap;
            }
            if (prefix > 0) {
                memcpy(buf + buf_len, subj + offset, prefix);
                buf_len += prefix;
            }
            if (repl_len > 0) {
                memcpy(buf + buf_len, repl, repl_len);
                buf_len += repl_len;
            }
            offset = end;
            if (end == start) {
                offset = end + 1;
            }
        }
    }

    pcre2_match_data_free_8(match_data);
    pcre2_code_free_8(code);

    if (NULL == buf) {
        buf = (char *) malloc(1);
        if (NULL == buf) {
            phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

            return NULL;
        }
        buf_len = 0;
    }
    buf[buf_len] = '\0';
    result = __string__init((long long) buf_len, buf);
    free(buf);
    phpc_preg_set_error(PHPC_PREG_NO_ERROR);

    return result;
}

#endif /* PHPC_HAVE_PCRE2 */

int64_t __compiler_preg_match(__string__ *pattern, __string__ *subject)
{
#ifdef PHPC_HAVE_PCRE2
    return phpc_preg_match_internal(pattern, subject);
#else
    (void) pattern;
    (void) subject;
    phpc_preg_set_error(PHPC_PREG_BAD_REGEX);

    return -1;
#endif
}

int64_t __compiler_preg_match_all(__string__ *pattern, __string__ *subject)
{
#ifdef PHPC_HAVE_PCRE2
    pcre2_code_8 *code;
    pcre2_match_data_8 *match_data;
    int preg_error = PHPC_PREG_NO_ERROR;
    int rc;
    int64_t count = 0;
    PCRE2_SIZE start_offset = 0;
    const char *subj = phpc_strdata(subject);
    size_t subj_len = phpc_strlen(subject);

    code = phpc_compile(pattern, &preg_error);
    if (NULL == code) {
        phpc_preg_set_error(preg_error);

        return -1;
    }

    match_data = pcre2_match_data_create_from_pattern_8(code, NULL);
    if (NULL == match_data) {
        pcre2_code_free_8(code);
        phpc_preg_set_error(PHPC_PREG_INTERNAL_ERROR);

        return -1;
    }

    while (start_offset <= subj_len) {
        rc = pcre2_match_8(code, (PCRE2_SPTR8) subj, subj_len, start_offset, 0, match_data, NULL);
        if (rc < 0) {
            if (PCRE2_ERROR_NOMATCH == rc) {
                break;
            }
            pcre2_match_data_free_8(match_data);
            pcre2_code_free_8(code);
            phpc_preg_set_error(phpc_pcre2_error_to_preg(rc));

            return -1;
        }
        count++;
        {
            PCRE2_SIZE *ovector = pcre2_get_ovector_pointer_8(match_data);
            if (ovector[1] == ovector[0]) {
                start_offset = ovector[1] + 1;
            } else {
                start_offset = ovector[1];
            }
        }
        if (start_offset > subj_len) {
            break;
        }
    }

    pcre2_match_data_free_8(match_data);
    pcre2_code_free_8(code);
    phpc_preg_set_error(PHPC_PREG_NO_ERROR);

    return count;
#else
    (void) pattern;
    (void) subject;
    phpc_preg_set_error(PHPC_PREG_BAD_REGEX);

    return -1;
#endif
}

__string__ *__compiler_preg_replace(__string__ *pattern, __string__ *replacement, __string__ *subject)
{
#ifdef PHPC_HAVE_PCRE2
    return phpc_preg_replace_internal(pattern, replacement, subject);
#else
    (void) pattern;
    (void) replacement;
    (void) subject;
    phpc_preg_set_error(PHPC_PREG_BAD_REGEX);

    return NULL;
#endif
}

__string__ *__compiler_preg_replace_callback(__string__ *pattern, void *callback, __string__ *subject)
{
    (void) pattern;
    (void) callback;
    (void) subject;
    phpc_preg_set_error(PHPC_PREG_BAD_REGEX);

    return NULL;
}

__hashtable__ *__compiler_preg_split(__string__ *pattern, __string__ *subject)
{
    (void) pattern;
    (void) subject;
    phpc_preg_set_error(PHPC_PREG_BAD_REGEX);

    return NULL;
}
