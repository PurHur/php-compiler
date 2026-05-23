/*
 * preg_match() runtime for AOT/JIT (issue #93).
 * Uses libpcre2-8; patterns must use PHP delimiter syntax (#...#, /.../, etc.).
 * Returns match count (0 or 1) on success, -1 on compile/validation error.
 */

#define PCRE2_CODE_UNIT_WIDTH 8
#include <pcre2.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

static size_t pm_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *pm_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

#define PM_MAX_PATTERN 4096

/* PHP PREG_* codes (subset); updated by __compiler_preg_match failures. */
#define PHPC_PREG_NO_ERROR 0
#define PHPC_PREG_BAD_REGEX 6

static int phpc_preg_last_error = PHPC_PREG_NO_ERROR;

static int pm_is_delim_invalid(char c)
{
    if (c >= 'a' && c <= 'z') {
        return 1;
    }
    if (c >= 'A' && c <= 'Z') {
        return 1;
    }
    if (c >= '0' && c <= '9') {
        return 1;
    }
    if (c == ' ' || c == '\t' || c == '\n' || c == '\r') {
        return 1;
    }
    if (c == '\\') {
        return 1;
    }

    return 0;
}

static int pm_parse_modifiers(const char *mods, size_t mod_len, uint32_t *opts)
{
    for (size_t i = 0; i < mod_len; i++) {
        switch (mods[i]) {
        case 'i':
            *opts |= PCRE2_CASELESS;
            break;
        case 'm':
            *opts |= PCRE2_MULTILINE;
            break;
        case 's':
            *opts |= PCRE2_DOTALL;
            break;
        default:
            return -1;
        }
    }

    return 0;
}

static int pm_extract_body(
    const char *full,
    size_t full_len,
    char *out,
    size_t out_cap,
    uint32_t *opts
)
{
    if (full_len < 2 || full_len > PM_MAX_PATTERN) {
        return -1;
    }
    char delim = full[0];
    if (pm_is_delim_invalid(delim)) {
        return -1;
    }
    size_t close = 0;
    for (size_t i = 1; i < full_len; i++) {
        if (full[i] == '\\') {
            i++;
            continue;
        }
        if (full[i] == delim) {
            close = i;
            break;
        }
    }
    if (0 == close || close + 1 > full_len) {
        return -1;
    }
    size_t body_len = close - 1;
    if (body_len + 1 > out_cap) {
        return -1;
    }
    memcpy(out, full + 1, body_len);
    out[body_len] = '\0';
    *opts = 0;

    return pm_parse_modifiers(full + close + 1, full_len - close - 1, opts);
}

int64_t __compiler_preg_last_error(void)
{
    return (int64_t) phpc_preg_last_error;
}

int64_t __compiler_preg_match(__string__ *pattern, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int rc = pcre2_match(
        re,
        (PCRE2_SPTR) subj,
        subj_len,
        0,
        0,
        match_data,
        NULL
    );

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    if (rc < 0) {
        if (PCRE2_ERROR_NOMATCH == rc) {
            return 0;
        }

        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    return 1;
}

int64_t __compiler_preg_match_all(__string__ *pattern, __string__ *subject)
{
    const char *pat_full = pm_strdata(pattern);
    size_t pat_len = pm_strlen(pattern);
    const char *subj = pm_strdata(subject);
    size_t subj_len = pm_strlen(subject);

    phpc_preg_last_error = PHPC_PREG_NO_ERROR;

    if (pat_len > PM_MAX_PATTERN) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    char body[PM_MAX_PATTERN + 1];
    uint32_t opts = 0;
    if (0 != pm_extract_body(pat_full, pat_len, body, sizeof(body), &opts)) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int errorcode = 0;
    PCRE2_SIZE erroffset = 0;
    pcre2_code *re = pcre2_compile(
        (PCRE2_SPTR) body,
        PCRE2_ZERO_TERMINATED,
        opts,
        &errorcode,
        &erroffset,
        NULL
    );
    if (NULL == re) {
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    pcre2_match_data *match_data = pcre2_match_data_create_from_pattern(re, NULL);
    if (NULL == match_data) {
        pcre2_code_free(re);
        phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

        return -1;
    }

    int64_t count = 0;
    PCRE2_SIZE start_offset = 0;

    while (start_offset <= subj_len) {
        int rc = pcre2_match(
            re,
            (PCRE2_SPTR) subj,
            subj_len,
            start_offset,
            0,
            match_data,
            NULL
        );

        if (rc < 0) {
            if (PCRE2_ERROR_NOMATCH == rc) {
                break;
            }

            pcre2_match_data_free(match_data);
            pcre2_code_free(re);
            phpc_preg_last_error = PHPC_PREG_BAD_REGEX;

            return -1;
        }

        count++;

        PCRE2_SIZE *ovector = pcre2_get_ovector_pointer(match_data);
        PCRE2_SIZE match_end = ovector[1];
        if (match_end == start_offset) {
            start_offset++;
        } else {
            start_offset = match_end;
        }
    }

    pcre2_match_data_free(match_data);
    pcre2_code_free(re);

    return count;
}
