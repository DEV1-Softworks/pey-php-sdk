# Changelog

All notable changes to `dev1/pey-php-sdk` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project scaffold: Composer package `dev1/pey-php-sdk`, PHPUnit 11, PHPStan at level max with strict rules (no baseline), PHP-CS-Fixer with PER-CS 3.0, and a coverage gate (90% on `src/`, 100% on `Money/`, `Security/`, `Validation/` and `Webhooks/`).
- GitHub Actions CI on PHP 8.2–8.5, with a gitleaks secret scan.
- `Version` class with the SDK user agent.

[Unreleased]: https://github.com/DEV1-Softworks/pey-sdk/commits/develop
