# SPA-Serve security regression contract

## 1. Protected coverage

Security tests protect the files and generated HTML exposed by SPA-Serve.
[AGENTS.md](../AGENTS.md) governs every direct or indirect change to this coverage.
An agent must not remove a failing attack case, loosen its expected result or
disable its execution without the exact-diff manual approval specified there.

Exercise the public middleware through PSR-7 requests and inspect the returned
response, using temporary real files and an outside-root secret sentinel. Check
both root and prefixed mounts. HTML tests inspect generated output rather than
only an internal escaping helper. Positive controls must retain legitimate
assets, client-side routes and escaped configuration values.

## 2. Regression areas

The suite must distinguish a rejected request from a successful SPA fallback and
must inspect status, body and relevant headers. A thrown exception alone is not
proof that the client receives a safe response. Do not accept arbitrary errors
as successful security assertions.

| Area | Required checks |
| --- | --- |
| Directory boundaries | Parent segments, encoded separators, repeated encoding, mixed separators, absolute paths and similarly named sibling directories. |
| Filesystem resolution | Escaping file and directory symlinks, broken links, directories instead of files and invalid or missing bundle roots. |
| Sensitive files | Hidden paths and backend/source filenames, including encoded and query-bearing variants, according to the module's serving policy. |
| Malformed input | Invalid percent encoding, NUL/control characters, malformed request paths and normalization differences at the server boundary. |
| Failure handling | Controlled rejection or server error without outside-root contents, reflected attack markup, filesystem paths, PHP warnings or stack traces. |
| Fallback | Legitimate client-side routes can receive the SPA; unsafe paths and missing assets must not turn into successful HTML responses. |
| HTML negotiation | Accept quality/specificity, charset parameters, quoted values, repeated fields, malformed or oversized headers and Fetch destinations must not turn CSS/JavaScript requests into HTML. |
| Asset diagnostics | Missing resources identify only the encoded public URL directory and a fixed resource hint; never echo query values, attack markup or local filesystem paths. |
| HTTP behavior | Supported methods, bodyless HEAD responses and content types with `X-Content-Type-Options: nosniff`; unsupported methods cannot disclose file contents. |
| Generated HTML | Title, meta names/values, asset attributes and base/API configuration remain in their intended text or attribute context. |

When simulating malformed webserver data, verify that the request object actually
delivers those bytes to the middleware. A URI constructor may already normalize
or encode a payload. Do not mistake that normalization for a middleware defense.
Use bounded fixtures; never contact external hosts or read real host secrets.

The module decodes a request path once before routing, excludes API routes from
asset-specific restrictions, and rejects ambiguous encodings and invalid path
forms. Asset policy denies hidden segments, backend/package metadata, server
source and backup/key files, Windows devices and alternate data streams. The
resolved target of an internal symlink must satisfy the same public-file policy.
Requests are limited to 8192 raw path bytes and asset segments to 255 bytes.

SPA-owned GET and HEAD requests are supported; HEAD responses have no body.
Invalid/private/missing assets return 404, unsupported methods 405 with an Allow
header, oversized paths 414, and operational bundle/render failures 500. These
errors are plain text with `nosniff` and `Cache-Control: no-store`. Missing public
resources use `File not Found (SPA-Serve Asset Path: /public/directory/).`, with
percent-encoded URL segments and an optional fixed CSS/JavaScript or rejection
hint. Private/invalid paths retain generic diagnostics. No error may expose raw
attack markup, query values or internal filesystem diagnostics. API handlers
retain their own behavior.

HTML fallback must honor the effective quality of `text/html; charset=utf-8`,
including an explicit `q=0` overriding a positive wildcard. Missing Accept means
no media preference; a present empty field accepts no representation. Conflicting
duplicate ranges, duplicate parameters and malformed weights reject fallback.
Accept parsing is bounded to 8192 bytes and 64 list members, including empty
members; non-ASCII header bytes are rejected by this module's strict parser.

Explicit positive CSS/JavaScript preferences outrank lower-quality HTML and tied
HTML wildcards. Fetch destinations other than absent metadata or the literal
tokens `empty`, `document`, `frame` and `iframe` reject fallback. Unknown or
malformed destinations also reject it as a conservative module policy. Generated
HTML and missing-resource diagnostics vary on `Accept, Sec-Fetch-Dest`.
Successful public-file reads and excluded API requests bypass this negotiation.
Exercise these guarantees in development and production, including HEAD and
renderer-failure sentinels that prove rejection happens before HTML generation.

## 3. Research basis

These published failures motivate regression cases. They are not claims that
SPA-Serve uses the affected libraries or has every listed vulnerability.

| Primary source | Lesson for this suite |
| --- | --- |
| [Starlette CVE-2023-29159][starlette-prefix] | A string prefix is not a directory boundary; include similarly named siblings. |
| [Apache CVE-2021-41773 / CVE-2021-42013][apache] | Test encoded traversal and normalization differences; the first fix was incomplete. |
| [Vite query bypass][vite-query] | Adding query parameters must not bypass the actual file-access policy. |
| [Vite Windows backslash bypass][vite-backslash] | URL checks and filesystem interpretation can disagree about separators. |
| [Starlette Windows UNC disclosure][starlette-unc] | Rejection after filesystem resolution can be too late; unsafe paths must not trigger network I/O. |
| [Werkzeug 3.1.9 security fix][werkzeug] | Windows device names and alternate data streams need explicit treatment where supported. |
| [Express September 2024 security releases][express] | Asset redirects and generated response HTML can introduce XSS. |
| [OWASP XSS prevention guidance][owasp] | Escaping must match the output context, including the surrounding HTML. |
| [RFC 9110 Accept semantics][http-accept] | Missing Accept, wildcards, weights and media parameters have distinct meanings; a broad wildcard cannot override a specific HTML rejection. |
| [Fetch default Accept headers][fetch] | Script requests can accept everything, and stylesheet requests can include a low-quality wildcard; HTML acceptability alone does not identify navigation. |
| [Fetch Metadata][fetch-metadata] | Use resource destinations to reject inappropriate fallback and include the relevant request headers in Vary. |

[starlette-prefix]: https://github.com/Kludex/starlette/security/advisories/GHSA-v5gw-mw7f-84px
[apache]: https://httpd.apache.org/security/vulnerabilities_24.html#CVE-2021-42013
[vite-query]: https://github.com/vitejs/vite/security/advisories/GHSA-9cwx-2883-4wfx
[vite-backslash]: https://github.com/vitejs/vite/security/advisories/GHSA-93m4-6634-74q7
[starlette-unc]: https://github.com/Kludex/starlette/security/advisories/GHSA-wqp7-x3pw-xc5r
[werkzeug]: https://werkzeug.palletsprojects.com/en/stable/changes/#version-3-1-9
[express]: https://expressjs.com/en/blog/2024-09-29-security-releases/
[owasp]: https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
[http-accept]: https://www.rfc-editor.org/rfc/rfc9110.html#section-12.5.1
[fetch]: https://fetch.spec.whatwg.org/#concept-fetch
[fetch-metadata]: https://www.w3.org/TR/fetch-metadata/

## 4. Trusted configuration and deployment limits

Serve a deliberately published, trusted bundle. Its directory and contents must
not be writable by untrusted users or changed concurrently while requests are
served. Containment checks do not provide an atomic race-proof filesystem sandbox
or detect every hard link. Uploaded files require a separate trust policy.

A filename denylist cannot detect a secret stored as an ordinary allowed asset.
Source maps (`.map`) and unknown public artifacts may be intentionally published;
review the build output itself and never put private data in it. Do not describe
an allowed public filename as proof that its contents are safe to publish.

`HtmlGenerator::$startHtml` deliberately contains trusted application HTML.
Configured JavaScript/CSS URLs and Vite development endpoints are also trusted
application configuration. Attribute escaping does not make an attacker-chosen
script source safe. Do not populate these values from untrusted request data.
Existing title/meta escaping is a positive control, not an observed XSS finding.

Accept and Fetch metadata select content; they are not authentication or access
control. Missing extensions, wildcard-only acceptance and absent destination
metadata cannot distinguish an asset client from navigation. Such requests may
receive the shell for compatibility; asset URLs or explicit media/destination
headers provide the required distinction.

Tests cover requests that reach SPA-Serve. They cannot secure Apache/Nginx rules
that serve private files directly, bypass the middleware, or introduce separate
HTTP parsing flaws. Such deployments need tests against their actual webserver
configuration. Passing this suite does not prove Windows filesystem behavior,
atomic filesystem containment or the absence of all possible vulnerabilities.

## 5. Running and reporting

```sh
composer test:security
composer test
```

`test:security` runs PHPUnit with `phpunit.security.xml`; `test` also runs PHPSpec.
The security gate must reject skips, incomplete/risky tests, warnings and an empty
suite. CI uses Linux, PHP 8.5, an unprivileged OS user, Composer dependencies,
DOM/XML/mbstring extensions and symlink support. Permission fixtures must actually
be unreadable to that user. Missing capabilities must remain visible failures or
reported execution limitations; do not insert automatic platform skips. Report actual commands and
results, plus any boundary that was not tested. This document is not a test-run
receipt and does not assert that a particular revision has passed.
