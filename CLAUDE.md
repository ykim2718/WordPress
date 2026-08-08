# WordPress

## Git workflow

**Every change ends up on `main`.** This is the repository owner's standing
instruction, restated on 2026-08-08: always merge to `main` and push.

- Never leave work sitting only on a working branch. Finishing a task means the
  commit is on `origin/main`.
- Do not open a pull request unless explicitly asked for one. A PR is not a
  substitute for pushing to `main`.
- If the session assigns a `claude/*` working branch, commit there as
  instructed, then land the same commit on `main` in the same step:

  ```bash
  git push origin <branch>:main      # land it on main
  git push -u origin <branch>        # keep the working branch in sync
  ```

- If `main` has moved ahead, rebase the working branch onto `origin/main`
  first, then push. Do not force-push `main`.
- Report the resulting `main` commit hash when the task is done.
