# Changelog

All notable changes to `axiomasoft/vaulter-editor-pro-review` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- Cursor paging for suggestion and pin HTTP lists and PHP operations. Supply `per_page` or `cursor` to receive `meta.next_cursor`. Supplying `open_only` also selects the paged suggestion response.
- Review migration 000805 indexes each document's suggestions and revision pins in cursor order.

### Changed

- Review and its base Vaulter dependencies install at the same version. Review services are scoped per request or job.

- A document generation holds at most 1000 open suggestions
  (`SuggestionOperations::MAX_OPEN_PER_DOCUMENT`). Saving a new suggestion beyond that answers
  `422 unsupported_suggestion` with reason `open_suggestion_limit`; amending a draft and
  deciding suggestions still work. Pending journal imports reserve capacity through the optional
  `PendingSuggestionReservations` contract. Mutation queries exclude unrelated decided suggestions.
- The `vaulter-editor-pro-review` config section is validated strictly: an unknown key, a
  non-boolean `pins.enforce_retention` or an anchor limit below its minimum (selector 256 bytes,
  depth 2, quote 64 bytes) is reported by `packman:config:validate` and `vaulter:doctor`
  instead of falling back to the default.
- Every review problem code has an English and a Russian title and detail.
- Suggestion overlap detection indexes list and table containers once per document; large
  documents with many suggestions are checked in milliseconds instead of seconds.
