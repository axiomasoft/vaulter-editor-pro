# Changelog

All notable changes to `axiomasoft/vaulter-editor-pro-review` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- The `vaulter-editor-pro-review` config section is validated strictly: an unknown key, a
  non-boolean `pins.enforce_retention` or an anchor limit below its minimum (selector 256 bytes,
  depth 2, quote 64 bytes) is reported by `packman:config:validate` and `vaulter:doctor`
  instead of falling back to the default.
- Every review problem code has an English and a Russian title and detail.
- Suggestion overlap detection indexes list and table containers once per document; large
  documents with many suggestions are checked in milliseconds instead of seconds.
