# Agent instructions

## 1. Security contract

Read this file and [tests/SECURITY.md](tests/SECURITY.md) before changing this
repository. These rules apply to every AI agent, coding assistant and automated
maintenance task working on SPA-Serve. Preserve the existing security guarantees
and report concrete security findings, their prerequisites and their impact.

## 2. Protected changes

Every change to security tests is security-sensitive, including additions,
providers, payloads, fixtures, assertions, helpers and bootstrap code. The same
protection covers PHPUnit configuration, Composer commands and dependencies used
to run these tests, CI selection and execution, and these protection documents.
Moving a security check elsewhere does not remove this protection.

## 3. Explicit authorization

Before a protected change, state its exact scope and security effect and obtain
explicit user authorization. An explicit request to add the described security
coverage or hardening authorizes that work; do not ask again within that approved
scope. Generic requests such as "fix tests" or "fix CI", a failing build, and an
agent's own assessment do not authorize weakening or disabling protection.

## 4. Manual approval before weakening

Before deleting, skipping, disabling or excluding a test, reducing a provider's
cases, weakening an assertion, changing an expected security status, changing a
fixture's security meaning, or catching an error that previously failed a test,
show the exact proposed diff and explain the lost or changed protection. Obtain
explicit manual user approval for that diff before applying it. This also covers
renames or moves that affect discovery, test filters, CI conditions,
`continue-on-error`, failure suppression, and relaxing this approval rule.
Approval is limited to the disclosed change; agents cannot approve their own
changes or infer blanket permission. Never disable a test to make a build green.

## 5. Preserve the regression evidence

Keep existing attack cases and positive controls. Add a reproducing case for a
new finding and correct the responsible implementation within the authorized
scope. Do not change the test to accept the vulnerable behavior. An equivalent
refactor still needs authorization under section 3; a change affecting protection
also needs the exact-diff approval in section 4. Report any remaining failure or
unverified behavior honestly instead of hiding it.

## 6. Required execution

Run `composer test:security` for the security suite and `composer test` for the
complete repository checks. Security execution must fail on skipped, incomplete
or risky tests, warnings, an empty suite and ordinary failures. Required CI uses
Linux, PHP 8.5, an unprivileged OS user, installed Composer dependencies and
functioning symbolic links; retain any additional capabilities required by the
tests. An unsupported local environment is a verification limitation, not
permission for silent
platform skips or weaker CI. Record the command, result and remaining limits.

## 7. Implementation fixes and scope

These rules do not introduce another approval gate for an already authorized
implementation bug fix with protected tests unchanged. Keep changes focused on
SPA-Serve; do not modify Core, filesystem libraries or other repositories without
the user's authorization. Preserve trusted-configuration boundaries documented
in `tests/SECURITY.md` and do not claim complete security from a passing suite.
