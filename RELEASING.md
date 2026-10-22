# Releasing

Jev Moderation for Laravel follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, breaking changes bump the minor version (`0.1.x` to `0.2.0`) and are called out under `### Changed` or `### Removed` in the changelog.

## Steps

1. Make sure `main` is green on the `tests` and `static analysis` workflows.
2. Run the full local gate: `composer check`, then `sh .github/scripts/check-archive.sh`.
3. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `## [Unreleased]` above it, and update the links at the bottom:

   ```markdown
   [Unreleased]: https://github.com/phoenix1331/laravel-jev-moderation/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/phoenix1331/laravel-jev-moderation/compare/vA.B.C...vX.Y.Z
   ```

4. If the release contains a breaking change or a new migration, add an entry to `UPGRADING.md` (create it on the first breaking change).
5. Check every sibling package constraint in `composer.json` points at a tagged Packagist version, never a path repository.
6. Commit: `chore: release vX.Y.Z`.
7. Tag and push, signing the tag:

   ```bash
   git tag -s vX.Y.Z -m "vX.Y.Z"
   git push origin main vX.Y.Z
   ```

8. The `release` workflow creates the GitHub release from the matching `CHANGELOG.md` section. It fails if that section is missing.
9. Packagist picks up the tag through the GitHub webhook. Check the new version appears at <https://packagist.org/packages/phoenix1331/laravel-jev-moderation>.

## First release only

- The README is written and the GitHub Pages site (`docs/index.html`) is published from `/docs` on `main`.
- The repository description, topics and homepage are set on GitHub.
- The package is submitted at <https://packagist.org/packages/submit> and the GitHub webhook is connected, so later tags publish automatically.
