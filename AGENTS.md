# Working on Leopard

- `leopard.sh` is the launcher. It must stay POSIX sh: Alpine has no bash.
- `leopard.php` holds the commands that print data or manage sites. It runs on
  the bundled PHP, so it must work on every PHP version in the CI matrix (8.3+).
- `skills/using-leopard/SKILL.md` is the guide for agents that use Leopard. It ships
  inside the bundle (`leopard skill`). Change it with any change to commands,
  flags, JSON fields, or exit codes.
- Build with `./build.sh` (`PHP_VERSION=8.5 ./build.sh` for another PHP) and
  test with `./test.sh dist/leopard-php<version>-linux-$(uname -m)`. Both need
  Docker. A build takes a few minutes.
- `test.sh` checks behavior through the public commands. Add a check there for
  each new command or flag.
