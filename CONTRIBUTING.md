# Contributing

Thank you for contributing to EDD Composer Extension.

Read the [architecture notes](docs/architecture.md) before changing package publication, authentication, routing, caching, or repository boundaries.

## Development expectations

- Keep production plugin files inside `edd-composer/`; keep source, tests, and tooling at the repository root.
- Follow the WordPress PHP, JavaScript, CSS, documentation, accessibility, security, and internationalization standards.
- Preserve the `EDD_Composer` namespace and `edd_composer` prefixes.
- Sanitize input at boundaries, escape output for its context, and keep protected responses non-cacheable.
- Never commit licensed EDD extensions, credentials, customer data, private URLs, or local environment files.
- Treat generated files in `edd-composer/assets/` as release artifacts: change their sources, rebuild them, and commit the resulting output.

## Before submitting a change

Install dependencies and provide the ignored licensed test plugins described in [`README.md`](README.md). Then run:

```sh
pnpm run check
```

For a smaller development loop, use the relevant lint command, JavaScript tests, or `pnpm run test`. Add focused coverage for changed behavior and keep visual-only assertions in manual browser review.

`pnpm run lint` is safe to run without private dependencies. `pnpm run phpstan` uses the real ignored EDD Pro and Software Licensing sources and therefore requires the local test plugins described in the README.

Commits should be focused and use a clear, descriptive imperative message.
