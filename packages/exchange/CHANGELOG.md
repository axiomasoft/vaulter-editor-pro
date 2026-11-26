# Changelog

All notable changes to `axiomasoft/vaulter-editor-pro-exchange` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `ExchangeErrorCode` exposes `httpStatus()`, `category()` and `retryable()`.

### Fixed

- On MySQL the job identity uses byte-exact scope, actor and request keys up to 255 bytes. Fresh UTF-8 installations now fit the InnoDB index limit. Migration 000806 updates existing installations and rerunning an interrupted 000801 preserves existing rows and completes missing indexes and foreign keys.

- The last worker failure records a terminal result if the worker can claim the job. A worker fenced by another attempt cannot fail that attempt.
- Recovery redelivers expired workers and aged queued jobs when automatic dispatch is enabled. The recovery API returns the IDs it scheduled, including stranded queued jobs.
- Unsupported or invalid media now fails the exchange job immediately and retains the original problem code.
- DOCX apply checks the open-suggestion limit before publishing an import. A refused import returns `422 exchange_invalid_command` with reason `open_suggestion_limit`. Migration 000804 reserves journal import slots until the command is rejected or its suggestions are materialized. Retention preserves a pending import and its artifacts while its room command can still settle.

### Changed

- Exchange and its base Vaulter dependencies install at the same version. Exchange services and the optional persistent converter are scoped per request or job.

- `exchange_job_not_ready`, `exchange_runtime_failed` and `exchange_lease_lost` answer
  `retryable: with_backoff`; they are transient and used to say `never`.
- Every exchange problem code has an English and a Russian title and detail.
