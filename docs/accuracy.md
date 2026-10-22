# Accuracy

Measured 2026-10-22 against live Jev (`jev-1.14.0`) with `composer test:accuracy`. Synthetic labelled posts in `tests/Accuracy/Corpus/`; "acceptable" means not the ideal outcome but not wrong (a rude but fair post being flagged, say).

| Preset | Samples | Exact | Exact or acceptable | Missed harmful | Wrongly blocked | Over-flagged | Flagged share | Errors | Input tokens |
|---|---|---|---|---|---|---|---|---|---|
| `chat` | 14 | 86% | 100% | 0 | 0 | 0 | 21% | 0 | 7001 |
| `community` | 24 | 75% | 100% | 0 | 0 | 0 | 25% | 0 | 12558 |
| `strict` | 15 | 73% | 100% | 0 | 0 | 0 | 27% | 0 | 8563 |

## Caught by category

Share of samples labelled with a risky category that were flagged or blocked.

| Preset | Category | Samples | Caught |
|---|---|---|---|
| `chat` | `harassment` | 2 | 100% |
| `chat` | `hate` | 1 | 100% |
| `chat` | `personal_data` | 2 | 100% |
| `chat` | `sexual` | 2 | 100% |
| `chat` | `spam` | 2 | 100% |
| `community` | `harassment` | 4 | 100% |
| `community` | `hate` | 3 | 100% |
| `community` | `off_topic` | 2 | 100% |
| `community` | `personal_data` | 2 | 100% |
| `community` | `spam` | 4 | 100% |
| `strict` | `fine` | 1 | 100% |
| `strict` | `harassment` | 2 | 100% |
| `strict` | `hate` | 1 | 100% |
| `strict` | `off_topic` | 1 | 100% |
| `strict` | `personal_data` | 2 | 100% |
| `strict` | `self_harm` | 2 | 100% |
| `strict` | `sexual` | 1 | 100% |
| `strict` | `spam` | 1 | 100% |

## Not exact

| Preset | Sample | Expected | Actual | Outcome | Deciding category | Probability | Confidence |
|---|---|---|---|---|---|---|---|
| `chat` | `spam-promo` | flagged | blocked | acceptable | `spam` (block_threshold) | 1.00 | 1.00 |
| `chat` | `harassment-direct` | flagged | blocked | acceptable | `harassment` (block_threshold) | 0.97 | 0.96 |
| `community` | `spam-subtle-affiliate` | flagged | blocked | acceptable | `spam` (block_threshold) | 1.00 | 1.00 |
| `community` | `harassment-insult` | flagged | blocked | acceptable | `harassment` (block_threshold) | 1.00 | 1.00 |
| `community` | `harassment-pile-on` | flagged | blocked | acceptable | `harassment` (block_threshold) | 1.00 | 1.00 |
| `community` | `hate-exclusion` | flagged | blocked | acceptable | `hate` (block_threshold) | 0.99 | 0.99 |
| `community` | `hate-disability` | flagged | blocked | acceptable | `hate` (block_threshold) | 0.98 | 0.98 |
| `community` | `personal-own-contact` | passed | flagged | acceptable | `personal_data` (review_threshold) | 0.54 | 0.45 |
| `strict` | `bullying-name-calling` | flagged | blocked | acceptable | `harassment` (block_threshold) | 0.99 | 0.99 |
| `strict` | `bullying-exclusion` | flagged | blocked | acceptable | `harassment` (block_threshold) | 0.99 | 0.99 |
| `strict` | `sexual-innuendo` | flagged | blocked | acceptable | `sexual` (block_threshold) | 0.97 | 0.97 |
| `strict` | `personal-school-handle` | flagged | blocked | acceptable | `personal_data` (block_threshold) | 0.98 | 0.98 |
