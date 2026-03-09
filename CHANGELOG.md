# Changelog

## v1.5.2-db
- Reworked **Class Stats** so values are calculated as the **average of per-race values matching the active filter**.
- Updated **Class Graph** to follow the same filters:
  - Class
  - Start Date
  - End Date
- Updated **Class → Races** drilldown so it also follows the active date filter.
- Made Class Stats, Graph, and Races drilldown consistent with each other.

## v1.5.1-db
- Refined Race View row visibility rules.
- Kept boats visible when they are excluded under **RYA 66% only** or **Best only**, because they may still be valid for **All/SD**.
- Hid only:
  - manually excluded rows
  - rows with no usable corrected value and excluded in all methods

## v1.5.0-db
- Added **Class Stats filters** for:
  - Class
  - Start Date
  - End Date
- Hid excluded / invalid rows from the visible Race View table in the initial implementation.
- This behavior was later refined in `v1.5.1-db`.

## v1.4.9-db
- Forced Race View to render:
  - included / valid rows first
  - excluded / invalid rows last
- Added muted styling for excluded rows.

## v1.4.8-db
- Rebuilt display ranks after sorting.
- Stopped client-side default ordering from pushing excluded rows back to the top.
- Excluded / invalid rows now receive blank display rank.

## v1.4.7-db
- Changed Race View sorting so excluded / invalid rows are pushed to the bottom instead of being sorted as zero-corr rows.

## v1.4.6-db
- Added final HTML exclusion pass for rows with no usable:
  - Elapsed
  - Corr.
  - Source Achieved PY
- Such rows are automatically marked excluded across methods.
- Intended for non-starters / bad pasted HTML rows that slipped through parsing.

## v1.4.5-db
- Expanded HTML parser support for sail number headers:
  - `Sail Number`
  - `Sail #`
  - `Sail No.`
  - `Sail ID`
  - `Bib`
- Added fallback sail-number inference for pasted HTML rows.
- Added HTML result-code handling for:
  - `OCS`
  - `DNS`
  - `DNF`
  - `DSQ`
  - `DNC`
  - `RET`
  - `BFD`
  - `UFD`
  - related codes
- Marked these rows as manually excluded with reason.

## v1.4.4-db
- Added **Reload stored HTML** button on Race View.
- Re-parses stored `srp_raw_html`.
- Rewrites parsed rows.
- Recalculates stats.
- Refreshes DB-backed summaries.

## v1.4.3-db
- Fixed HTML parsing where `Class` was incorrectly populated with fleet buckets such as:
  - `Fast`
  - `Slow`
- Added logic to use actual boat/design value as `Class`.
- Preserved original fleet grouping separately as `Fleet`.

## v1.4.2-db
- Improved HTML copy/paste normalization.
- Added wider HTML header recognition for:
  - `Corrected`
  - `Corrected Time`
  - `Achieved`
  - `Achieved PY`
  - `Rating`
  - `Yardstick`
- Added repair pass for pasted RYA-style HTML tables.
- Preserved source-sheet achieved values as **Source Achieved PY** where available.

## v1.4.1-db
- Fixed DB graph crash caused by invalid `None` value in PHP.
- Added Class Stats drilldown link:
  - **Races**
- Improved DB summary fallback for some HTML-imported races.

## v1.4.0-db
- Introduced the **DB-backed architecture**.
- Added new tables:
  - `wp_srp_races`
  - `wp_srp_results`
  - `wp_srp_classes`
  - `wp_srp_race_class_stats`
  - `wp_srp_class_stats`
- Stored **RYA race_id** in:
  - races
  - results
- Stored **class_rya_id** in:
  - classes
  - races
  - results
  - race class stats
- Added DB sync on:
  - import save/update
  - per-race recalculate
  - recalculate all
- Added **Migrate existing races to DB** tool action.
- Switched Class Report and Graph to DB-backed reporting.

## Pre-DB stabilization highlights

### v1.3.3
- Improved recalculate-all reliability.
- Fixed exclusions warning in Class Stats.
- Stabilized graph handling and trendlines.

### v1.3.2
- Improved HTML import normalization so pasted tables more closely matched spreadsheet imports.

### v1.3.0–v1.3.1
- Added exclusions column and non-paging Class Stats table.
- Improved class graph trendlines.
- Improved recalculate-all robustness.

## Notes
- `v1.4.x-db` focused on moving reporting and summaries onto DB tables.
- `v1.4.2-db` onward focused heavily on **HTML paste import repair**.
- `v1.5.x-db` focused on **Race View visibility rules** and **filtered Class Stats / Graph correctness**.
