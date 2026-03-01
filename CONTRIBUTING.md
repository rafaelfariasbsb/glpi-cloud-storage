# Contributing to Cloud Storage for GLPI

Thank you for your interest in contributing!

## Getting Started

1. Fork the repository
2. Clone into your GLPI plugins directory (see [Development Guide](docs/07-development-guide.md))
3. Install dependencies: `composer install`
4. Create a feature branch: `git checkout -b feature/my-feature`

## Code Standards

- Follow PSR-12 coding style
- Use `GlpiPlugin\Cloudstorage\` namespace with PSR-4 autoloading
- Never modify GLPI core files — use hooks only
- Log errors at both levels (see [Development Guide — Logging](docs/07-development-guide.md#logging-two-tier-strategy))
- Sanitize all error messages before display (never expose credentials)

## Pull Requests

1. Keep PRs focused on a single change
2. Update documentation if your change affects user-facing behavior
3. Update `CHANGELOG.md` under an `[Unreleased]` section
4. Ensure `composer validate` passes

## Reporting Issues

Use [GitHub Issues](https://github.com/rafaelfariasbsb/glpi-cloud-storage/issues) with:

- GLPI version and PHP version
- Plugin version
- Steps to reproduce
- Expected vs actual behavior
- Relevant logs from `files/_log/cloudstorage.log` (redact credentials)

## License

By contributing, you agree that your contributions will be licensed under the GPL-3.0-or-later license.
