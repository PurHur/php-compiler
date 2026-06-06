/**
 * JIT/AOT segfault triage — thin platform ABI only (#2978, #6748).
 * Progress file writes live in lib/JIT/Builtin/ProgressNoteRuntime.php + lib/JIT/Progress.php.
 */
#include <stddef.h>
#include <string.h>
#include <signal.h>
#include <unistd.h>

static int phpc_segv_handler_installed = 0;
static char phpc_last_progress[256];
static size_t phpc_last_progress_len = 0;

static void phpc_segv_handler(int sig)
{
    (void) sig;
    if (phpc_last_progress_len > 0) {
        (void) write(2, "phpc: fatal signal (segfault) after ", 37);
        (void) write(2, phpc_last_progress, phpc_last_progress_len);
        (void) write(2, "\n", 1);
    } else {
        (void) write(2, "phpc: fatal signal (segfault)\n", 30);
    }
    _exit(139);
}

static void phpc_install_segv_handler(void)
{
    if (phpc_segv_handler_installed) {
        return;
    }
    phpc_segv_handler_installed = 1;
    (void) signal(SIGSEGV, phpc_segv_handler);
}

void __phpc_progress_remember(const char *msg)
{
    size_t len;

    phpc_install_segv_handler();
    if (NULL == msg) {
        return;
    }

    len = strlen(msg);
    if (len > 0) {
        if (len >= sizeof(phpc_last_progress)) {
            len = sizeof(phpc_last_progress) - 1;
        }
        memcpy(phpc_last_progress, msg, len);
        phpc_last_progress[len] = '\0';
        phpc_last_progress_len = len;
    }
}
