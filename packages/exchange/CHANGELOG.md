# Changelog

All notable changes to `axiomasoft/vaulter-editor-pro-exchange` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## Unreleased

- Exchange and its base Vaulter dependencies install at the same version. Exhausting queue retries records a terminal failure when the worker can claim the job.

### Changed

- `exchange_job_not_ready`, `exchange_runtime_failed` and `exchange_lease_lost` answer
  `retryable: with_backoff`; they are transient and used to say `never`.
- Every exchange problem code has an English and a Russian title and detail.
