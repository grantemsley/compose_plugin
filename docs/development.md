# Development Guide

This document describes how to set up a development environment, run unit and integration tests, and use the project tooling.

## Prerequisites

- PHP (match the project's platform requirements from `composer.json`)
- Composer (to install dev dependencies like PHPUnit and PHPStan)
- Git (for submodules)

## Setup

1. Clone the repository and install dependencies:

   ```bash
   git clone https://github.com/mstrhakr/compose_plugin
   cd compose_plugin
   composer install
   ```

2. Initialize submodules (integration tests):

   ```bash
   # If the submodule is configured in .gitmodules
   git submodule update --init --recursive

   # Or add it manually if you don't have it yet
   git submodule add https://github.com/mstrhakr/plugin-tests.git tests/plugin-tests
   git submodule update --init --recursive
   ```

3. Verify vendor binaries are available (e.g., `vendor/bin/phpunit`).

## Unit Tests

- Run all unit tests:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml
  ```

- Run the unit test suite specifically:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml --testsuite unit
  ```

- Run a single test file or method:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml tests/unit/SomeTest.php
  php vendor/bin/phpunit --config phpunit.xml --filter testSomething
  ```

- Generate coverage (HTML):

  ```bash
  php vendor/bin/phpunit --config phpunit.xml --coverage-html tests/coverage/html
  ```

Coverage output is written to `tests/coverage/` by default (see `phpunit.xml`).

## Integration Tests (plugin-tests)

- The project uses a separate `plugin-tests` framework for system-level and integration tests. After initializing the submodule, read its README for environment and runner details.

- Typical flow:
  - Ensure the test environment (a test unRAID instance or VM) is available and configured per `plugin-tests` instructions.
  - Run the framework's test runner (refer to `tests/plugin-tests/README.md` for exact commands). Commonly these are shell scripts in the submodule such as `./bin/run-tests.sh`.

## Static Analysis

- Run PHPStan:

  ```bash
  composer run analyse
  ```

Adjust PHPStan rules or baseline as needed when adding new code.

## CI / Local tips

- Ensure `composer install` is part of CI job setup and that any required submodules are initialized.
- Cache `~/.composer/cache` and `vendor` between CI runs where possible to speed builds.
- Use `--filter` or PHPUnit groups to run a focused subset of tests while developing.

## Writing Tests & Contributing

- Add unit tests under `tests/unit` and integration tests to the `plugin-tests` submodule following its conventions.
- Keep tests small and focused; mock external systems where possible in unit tests.
- Update `phpunit.xml` if you add new suites or change coverage targets.

## Troubleshooting

- If vendor binaries are missing, re-run `composer install`.
- If integration tests fail with environment errors, confirm the plugin-tests environment variables and the unRAID test instance are correct.
