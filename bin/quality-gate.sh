#!/usr/bin/env bash
#
# bin/quality-gate.sh — verify-only quality gate for Laravel base projects.
#
# Fail-fast order:
#   rector(9, --dry-run --no-progress-bar)
#   pint(2, --test)
#   phpstan(4, --memory-limit=2G)
#   wayfinder(7, :generate --with-form drift)
#   typescript:transform(8, git hash-object before/after generated.d.ts)
#   bun test:lint(5)
#   bun test:types(6)
#   pest(3, --parallel --exclude-testsuite=Browser then Browser serial)
#
# Exit codes: 2=pint 3=pest(browser) 4=phpstan 5=js-lint 6=js-types 7=wayfinder-drift
#             8=ts-transform-drift 9=rector 10=type-coverage 11=code-coverage
#             127=tool-missing 0=pass
#
# Full mode also enforces: pest --type-coverage --min=100 (exit 10) and
# code coverage --exactly=100.0 via bin/coverage.sh (exit 11; uses a loaded
# xdebug/pcov driver, else `herd coverage` on local Herd).
#
# --fast mode: scope rector/pint/phpstan to `git diff --name-only HEAD` *.php,
#              run tsc, SKIP pest, coverage, type-coverage + wayfinder/ts drift.
#
# Quiet by default: one ✔/✘ line per step; on failure dumps captured output.
# Pass --verbose/-v to stream every tool's output live.
#
# Fix commands:
#   exit 2  → vendor/bin/pint --dirty
#   exit 5  → bun run test:lint (vp lint)
#   exit 6  → bun run test:types (tsc)
#   exit 7  → php artisan wayfinder:generate --with-form
#   exit 8  → php artisan typescript:transform
#   exit 9  → vendor/bin/rector

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERBOSE=0
FAST=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose)
            VERBOSE=1
            shift
            ;;
        --fast)
            FAST=1
            shift
            ;;
        -h|--help)
            cat <<'EOF'
Usage: bin/quality-gate.sh [--verbose|-v] [--fast] [--help|-h]

Verify-only quality gate. Does NOT fix anything.

Modes:
  (default)  Full codebase: rector --dry-run, pint --test, phpstan,
             wayfinder drift, ts-transform drift, bun lint/types,
             type-coverage --min=100, code coverage --exactly=100.0
             (via bin/coverage.sh), pest Browser (serial).

  --fast     Scope rector/pint/phpstan to PHP files changed since HEAD
             (`git diff --name-only HEAD`); run tsc; SKIP pest, coverage,
             type-coverage + wayfinder/ts-transform drift. CI owns the full gate.

Exit codes: 2=pint 3=pest(browser) 4=phpstan 5=js-lint 6=js-types
            7=wayfinder-drift 8=ts-transform-drift 9=rector
            10=type-coverage 11=code-coverage 127=tool-missing

Fix commands:
  exit 2  → vendor/bin/pint --dirty
  exit 5  → bun run test:lint
  exit 6  → bun run test:types
  exit 7  → php artisan wayfinder:generate --with-form
  exit 8  → php artisan typescript:transform
  exit 9  → vendor/bin/rector
EOF
            exit 0
            ;;
        *)
            printf '\033[0;31mUnknown argument: %s\033[0m\n' "$1" >&2
            printf "Run 'bin/quality-gate.sh --help' for usage.\n" >&2
            exit 1
            ;;
    esac
done

# ─── helpers ────────────────────────────────────────────────────────────────

# step — print a ▶ start line in verbose mode only.
step() { [[ "$VERBOSE" -eq 1 ]] && printf '▶ %s\n' "$1"; return 0; }
ok()   { printf '✔ %s\n' "$1"; }
bad()  { printf '✘ %s\n' "$1" >&2; }

# have <path|cmd> — true if executable exists or is on PATH.
have() { [[ -x "$1" ]] || command -v "$1" >/dev/null 2>&1; }

# runq <cmd...> — verbose: stream live; quiet: capture combined output and
# dump it only when the command fails. Returns the command's exit status.
runq() {
    if [[ "$VERBOSE" -eq 1 ]]; then
        "$@"
        return $?
    fi
    local out status
    out="$("$@" 2>&1)"
    status=$?
    [[ $status -ne 0 && -n "$out" ]] && printf '%s\n' "$out" >&2
    return $status
}

# --fast: collect changed PHP files (relative to HEAD).
changed_php_files() {
    git diff --name-only HEAD 2>/dev/null \
        | grep '\.php$' \
        | xargs -r ls -1 2>/dev/null \
        | tr '\n' ' '
}

# ─── step functions ─────────────────────────────────────────────────────────

run_rector() {
    step "rector --dry-run"
    if ! have vendor/bin/rector; then
        bad "rector missing — vendor/bin/rector not found"
        exit 127
    fi

    if [[ "$FAST" -eq 1 ]]; then
        local files
        files=$(changed_php_files)
        if [[ -z "$files" ]]; then
            ok "rector (fast — no changed PHP files)"
            return 0
        fi
        # shellcheck disable=SC2086
        runq vendor/bin/rector process --dry-run --no-progress-bar --ansi $files \
            || { bad "rector (fast)"; exit 9; }
    else
        runq vendor/bin/rector --dry-run --no-progress-bar --ansi \
            || { bad "rector"; exit 9; }
    fi
    ok "rector"
}

run_pint() {
    step "pint --test"
    if ! have vendor/bin/pint; then
        bad "pint missing — vendor/bin/pint not found"
        exit 127
    fi

    if [[ "$FAST" -eq 1 ]]; then
        local files
        files=$(changed_php_files)
        # Pint ignores pint.json `notPath`/`exclude` when files are passed as
        # explicit arguments, so drop any changed file pint is configured to skip.
        if [[ -n "$files" && -f pint.json ]] && have php; then
            local skip
            skip=$(php -r '$j=json_decode(file_get_contents("pint.json"),true)?:[]; foreach(array_merge($j["notPath"]??[],$j["exclude"]??[]) as $p){echo $p,"\n";}' 2>/dev/null)
            if [[ -n "$skip" ]]; then
                # shellcheck disable=SC2086
                files=$(printf '%s\n' $files | grep -vxF -f <(printf '%s\n' "$skip") || true)
            fi
        fi
        if [[ -z "$files" ]]; then
            ok "pint (fast — no lintable changed files)"
            return 0
        fi
        # shellcheck disable=SC2086
        runq vendor/bin/pint --test --format=agent $files \
            || { bad "pint (fast)"; exit 2; }
    else
        runq vendor/bin/pint --test --format=agent || { bad "pint"; exit 2; }
    fi
    ok "pint"
}

run_phpstan() {
    step "phpstan analyse"
    if ! have vendor/bin/phpstan; then
        bad "phpstan missing — vendor/bin/phpstan not found"
        exit 127
    fi

    if [[ "$FAST" -eq 1 ]]; then
        local files
        files=$(changed_php_files)
        if [[ -z "$files" ]]; then
            ok "phpstan (fast — no changed PHP files)"
            return 0
        fi
        # PHPStan's analysed scope (and its tests/ exclusion) is pinned in
        # phpstan.neon `paths`. Passing changed files as explicit args overrides
        # that scope and pulls in unanalysable Pest test files, so run the
        # config-scoped analysis (fast enough on a scoped path set).
        runq vendor/bin/phpstan analyse --memory-limit=2G --no-progress --no-ansi \
            || { bad "phpstan (fast)"; exit 4; }
    else
        runq vendor/bin/phpstan analyse --memory-limit=2G --no-progress --no-ansi \
            || { bad "phpstan"; exit 4; }
    fi
    ok "phpstan"
}

run_wayfinder() {
    # Skipped in --fast mode; CI + full gate own drift detection.
    [[ "$FAST" -eq 1 ]] && return 0

    step "wayfinder:generate (drift check)"
    if ! [[ -f artisan ]]; then
        bad "artisan missing — cannot run wayfinder:generate"
        exit 127
    fi
    runq php artisan wayfinder:generate --with-form --quiet \
        || { bad "wayfinder:generate"; exit 7; }

    # Drift check: if generated files changed, report and exit 7.
    local changed_wayfinder
    changed_wayfinder=$(git diff --name-only -- 'resources/js/actions/**' 'resources/js/routes/**' 2>/dev/null)
    if [[ -n "$changed_wayfinder" ]]; then
        bad "wayfinder:generate — generated routes/actions were out of sync; regenerated now"
        [[ "$VERBOSE" -eq 1 ]] && printf '%s\n' "$changed_wayfinder" >&2
        exit 7
    fi
    ok "wayfinder:generate"
}

run_typescript_transform() {
    # Skipped in --fast mode; CI owns drift detection.
    [[ "$FAST" -eq 1 ]] && return 0

    # Skip when the ts-transformer module is not installed.
    if ! [[ -f config/typescript-transformer.php ]]; then
        [[ "$VERBOSE" -eq 1 ]] && printf '▷ typescript:transform — module absent, skipping\n'
        return 0
    fi

    step "typescript:transform (drift check)"
    if ! [[ -f artisan ]]; then
        bad "artisan missing — cannot run typescript:transform"
        exit 127
    fi

    local generated="resources/js/types/generated.d.ts"
    local before_hash=""
    if [[ -f "$generated" ]]; then
        before_hash=$(git hash-object "$generated")
    fi

    runq php artisan typescript:transform --quiet \
        || { bad "typescript:transform"; exit 8; }

    local after_hash=""
    if [[ -f "$generated" ]]; then
        after_hash=$(git hash-object "$generated")
    fi

    if [[ "$before_hash" != "$after_hash" ]]; then
        bad "typescript:transform — $generated was out of sync with PHP DTOs; it has now been regenerated"
        exit 8
    fi
    ok "typescript:transform"
}

run_js_lint() {
    step "bun test:lint"

    # Skip when bun is absent (base project may not have it yet during bootstrap).
    if ! have bun; then
        [[ "$VERBOSE" -eq 1 ]] && printf '▷ bun test:lint — bun not found, skipping\n'
        return 0
    fi

    # Skip when vite-plus is absent (no vp binary = no lint target).
    if ! [[ -f node_modules/.bin/vp ]]; then
        [[ "$VERBOSE" -eq 1 ]] && printf '▷ bun test:lint — vite-plus (vp) not installed, skipping\n'
        return 0
    fi

    runq bun run test:lint || { bad "bun test:lint"; exit 5; }
    ok "bun test:lint"
}

run_js_types() {
    step "bun test:types"

    if ! have bun; then
        [[ "$VERBOSE" -eq 1 ]] && printf '▷ bun test:types — bun not found, skipping\n'
        return 0
    fi

    runq bun run test:types || { bad "bun test:types"; exit 6; }
    ok "bun test:types"
}

run_type_coverage() {
    # Skipped in --fast mode. Fast (PHPStan-based, no coverage driver needed).
    [[ "$FAST" -eq 1 ]] && return 0

    step "pest --type-coverage --min=100"
    if ! have vendor/bin/pest; then
        bad "pest missing — cannot run type-coverage"
        exit 127
    fi
    runq vendor/bin/pest --type-coverage --min=100 \
        || { bad "type-coverage (<100%)"; exit 10; }
    ok "type-coverage (100%)"
}

run_code_coverage() {
    # Skipped in --fast mode; driver-backed coverage is slow. Runs the non-browser
    # suite WITH coverage (which also asserts those tests pass) at exactly 100%.
    # bin/coverage.sh picks the driver: loaded xdebug/pcov (CI) or `herd coverage` (local).
    [[ "$FAST" -eq 1 ]] && return 0

    step "code coverage --exactly=100.0 (excl. Browser)"
    if ! [[ -x bin/coverage.sh ]]; then
        bad "bin/coverage.sh missing — cannot run code coverage"
        exit 127
    fi
    runq bin/coverage.sh --exactly=100.0 --exclude-testsuite=Browser \
        || { bad "code coverage (<100% or no driver)"; exit 11; }
    ok "code coverage (100%)"
}

run_browser_tests() {
    # Always skipped in --fast mode. Browser tests run serially (Playwright), no coverage.
    [[ "$FAST" -eq 1 ]] && return 0

    if ! [[ -d tests/Browser ]]; then
        [[ "$VERBOSE" -eq 1 ]] && printf '▷ pest Browser — tests/Browser absent, skipping\n'
        return 0
    fi

    step "pest Browser (serial)"
    if ! [[ -f artisan ]]; then
        bad "artisan missing — cannot run browser tests"
        exit 127
    fi
    # PAO_DISABLE: laravel/pao flips the exit code to 1 even when all tests pass.
    runq env PAO_DISABLE=1 php artisan test --compact tests/Browser \
        || { bad "pest (browser)"; exit 3; }
    ok "pest (browser)"
}

# ─── execution order (contract: rector→pint→phpstan→wayfinder→ts→lint→types→pest) ──

run_rector
run_pint
run_phpstan
run_wayfinder
run_typescript_transform
run_js_lint
run_js_types
run_type_coverage
run_code_coverage
run_browser_tests

printf '✔ quality-gate passed\n'
exit 0
