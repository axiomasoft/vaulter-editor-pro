# Changelog

All notable changes to `@vaulter/editor-pro-vue` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- The default client exposes `pins.page()` and `suggestions.page()`. Each returns `data` and `meta.next_cursor`. Injected clients that implement only the original list methods remain supported.

### Changed

- The tracked changes panel loads decision history 50 records at a time and retains every open group member. Custom clients without a page method continue to use `list()`. Composable consumers can select this behavior with `pageHistory` and call `loadMoreHistory()`.

- Structural diffs build a block-container index once per document for large selections.
