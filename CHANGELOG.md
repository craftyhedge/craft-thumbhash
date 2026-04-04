# Release Notes for ThumbHash

## 2.0.0 - 2026-04-04

### Breaking Changes
- `data-thumbhash-bg` is no longer supported.
- Background placeholders must now use `data-thumbhash` with `data-thumbhash-render="bg"`.
- The client decoder now supports explicit render modes (`bg`, `picture`, `img`) and defaults to `bg` unless overridden.

### Added
- New Twig helper: `thumbhashTransparentSvg(width = 4, height = 4)`.
- New setting: `renderMethod` (`bg`, `picture`, `img`).
- New setting: `scriptPosition` (`head`, `end`).
- Picture placeholder propagation support for `<picture data-thumbhash ...>` wrappers.
- Introduced ratio cropped PNG placeholders for better aspect ratio handling and reduced layout shift for both the frontend JS decoder and backend generated data URLs.

### Changed
- Inline decoder config now includes `renderMethod`.
- Background placeholder behavior is now tied to the `bg` render mode instead of a dedicated `data-thumbhash-bg` attribute.

### Upgrade Guide
- Replace all `data-thumbhash-bg="..."` usages with `data-thumbhash="..." data-thumbhash-render="bg"`.
- If your implementation depends on setting `<img src>` from the hash, set `data-thumbhash-render="img"` per element, or set `'renderMethod' => 'img'` globally.
- No database or migration changes are included in this release.
- `schemaVersion` remains `1.0.0`.

## 1.0.0 - 2026-03-30
- Initial release
