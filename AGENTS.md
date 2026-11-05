# Working on Leopard

- `leopard.sh` is the launcher. It must stay POSIX sh: Alpine has no bash. It
  runs the tool shortcuts (php, wp, composer, mysql, shell, link, service)
  and hands every other command to `leopard.php`.
- `leopard.php` is the control plane: settings, sites, packs, logs, and every
  server process. It runs on the PHP pack the file came with, so it must work
  on every PHP version in the CI matrix (8.3+).
- Servers start through `spawn()` and stop through `stopProc()`. Keep both:
  they stop a started server from holding a lock or the caller's output pipe,
  and they clear workers left behind by a crash.
- `skills/using-leopard/SKILL.md` is the guide for agents that use Leopard. It ships
  inside the bundle (`leopard skill`). Change it with any change to commands,
  flags, JSON fields, or exit codes.
- Build with `./build.sh` (`PHP_VERSIONS="8.3 8.5" ./build.sh` for others) and
  test with `./test.sh dist/leopard-php<version>-linux-$(uname -m)`. Both need
  Docker. Packs take a few minutes each; after a change to `leopard.sh` or
  `leopard.php` only, `./build.sh files` rebuilds the files in seconds.
- `test.sh` checks behavior through the public commands. Add a check there for
  each new command or flag.
- To prove one change end to end with evidence (CLI output, browser
  screenshots), use the `verify-leopard` skill in `.agents/skills/`.
- When a change is done and tested, commit it and push to `main` without
  asking. Push a release tag (`v*`) only when the user asks for one: a tag
  publishes a GitHub release.
