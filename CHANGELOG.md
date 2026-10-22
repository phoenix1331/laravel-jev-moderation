# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `moderation_decisions` migration, published with the `jev-moderation-migrations` tag: polymorphic and append-only, with the answering Jev version, category, confidence, content hash and reviewer, portable across SQLite, PostgreSQL, MySQL and MariaDB
- `ModerationStatus` and `ModerationSource` enums
- Decisions are append-only: saving, deleting, or mass updating or deleting an existing decision throws `DecisionsAreAppendOnly`
- `ModerationDecisionFactory` with `pending`, `passed`, `flagged`, `blocked`, `reported`, `approved` and `rejected` states, for seeding demos and tests without Faker

[Unreleased]: https://github.com/phoenix1331/laravel-jev-moderation/commits/main
