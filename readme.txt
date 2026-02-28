Sail Results Parser v0.21.1
- Add: Tools page with global 'Recalculate all races'
- Add: Weather fields on race view (Wind/Direction/Temperature/Pressure)
- Fix: map rotation improved (pane selector + timing)
- IMPORTANT: To update version in WP, delete old plugin folder and upload this zip.

sail-results-parser.php so the AJAX save now: V22

applies your edits (PY/Laps/Elapsed + manual exclude)

runs srp_rya_normalize_rows_for_stats()
runs srp_compute_dual($rows, $k)
saves back updated:
srp_parsed_rows (with Derived PY/GL filled)
srp_class_stats
srp_calc_debug
srp_analysis_dual (both methods)
