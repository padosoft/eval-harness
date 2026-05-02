# Security Policy

## Reporting a vulnerability

If you discover a security vulnerability in `padosoft/eval-harness`,
please report it privately:

1. Email **lorenzo.padovani@padosoft.com** (or open a private GitHub
   security advisory at
   <https://github.com/padosoft/eval-harness/security/advisories/new>).
2. Provide details: affected version, reproduction steps, potential
   impact, suggested mitigation.
3. Wait for our acknowledgement before public disclosure.

We aim to respond within 48 hours and provide a fix within 30 days
for critical issues. We will credit you in the changelog and the
GitHub security advisory unless you prefer to remain anonymous.

## Supported versions

Only the latest stable minor release receives security patches.
Pre-1.0.0 releases (v0.x) are explicitly **not** stable; pin a
specific version in production.

## Scope

The package's threat model covers:

- Strict-JSON validation of LLM judge responses (resists prompt
  injection that tries to escape the judge contract).
- Defensive truncation of error messages so we never echo unbounded
  third-party payloads back to the operator.
- No persistence of API keys to disk, no logging of `Authorization`
  headers — every secret is read from env at request time.

The package's threat model does **not** cover:

- The host application's authentication / authorisation surface.
- The behaviour of the system-under-test callable. Whatever the
  caller's RAG pipeline does — including reading user input — is
  the caller's responsibility.
- The third-party LLM provider's content policies. The harness
  forwards prompts verbatim.
