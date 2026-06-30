# Progress

Rolling log of what the harness has built, what's next, what's blocked, and non-obvious decisions.
Every session reads this **first**, along with `git log --oneline -20`, before touching code.

## Discipline

- **Update every commit.** One entry per commit, newest on top.
- **Four fields, no free-form essays:**
  - **Built** — what this commit actually shipped (not what you hoped for).
  - **Next** — the single next concrete step. Not a wish list.
  - **Blocked** — what's stuck + why + who/what unblocks it. `—` if nothing.
  - **Decisions** — non-obvious choices made this commit. `—` if none.
- **Be brutal.** If a test was skipped, say so. If a mock hid a real bug, say so. Future sessions rely on honesty here more than on prose.
- Short. Bullet points. Fragments OK.
- Link relevant files with repo-relative paths.
- Reference the active feature-list file (`docs/agent/features/<slug>.md`) if one is in play.

## Format

```markdown
## YYYY-MM-DD — `<short-sha>` — <one-line summary>

- **Built:** …
- **Next:** …
- **Blocked:** —
- **Decisions:** —
```

## Entries

<!-- newest entry goes here -->
