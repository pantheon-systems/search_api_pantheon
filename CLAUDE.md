# search_api_pantheon

Drupal module providing Solr integration for Pantheon's Search service. Mirrored to drupal.org.

## Branches

- `8.x` — default/main branch
- `solr-ci` — CI rework branch (active development)

## CI Architecture

GitHub Actions workflow (`.github/workflows/ci.yml`) with these jobs:

1. **linting** — PHP code sniffing (PHP 8.1, 8.5)
2. **phpcompatibility** — PHP compatibility checks
3. **deploy_multidev** — Integration tests on Pantheon multidev environments
4. **mirror_do** — Pushes to drupal.org git mirror

### Multidev Test Matrix

Drupal 10/11 x PHP 8.1/8.3/8.4/8.5 x Solr 8/9 = 8 combinations (with exclusions pairing PHP floor/ceiling per Drupal version).

### Test Flow

Each matrix job runs these scripts sequentially:

1. `create-multidev.sh` — Creates multidev from dev, installs module via Composer, sets Solr/PHP version in pantheon.yml, creates a second "parity" multidev
2. `run-tests.sh` — Basic integration: Solr readiness polling, schema post (3 retries/60s), content generation, index verification (counts pre-existing content before `genc` to handle inherited nodes)
3. `test-field-mapping.sh` — Configures Search API index, posts schema, indexes pre-baked test node, validates Solr field type mappings (tm_, its_, fs_, bs_, ds_, ss_ prefixes)
4. `test-multidev-parity.sh` — Validates different multidevs get different Solr cores but same host/port
5. `cleanup-multidevs.sh` — Deletes stale CI multidevs (prefix match + 72h age cutoff)

### Script Locations

All CI scripts live in `.github/scripts/`. The `scripts/manual-tests/` directory was removed — all test functionality is now in `.github/scripts/`.

The `.ci/` directory is legacy (old CircleCI-era scripts, not referenced by current CI).

### Multidev Naming

Names are truncated to 11 chars (Pantheon limit). Format: `{drupal}{php}s{solr}-{run#}`, e.g. `1081s8-2630`. Parity env replaces last char with `b`, e.g. `1081s8-263b`. The `d`/`p` letter prefixes were dropped so the 7-char prefix leaves room for the full 4-digit run number (no truncation up to run 9999); the old `d{drupal}p{php}s{solr}-` form truncated the run number away, causing name collisions across concurrent runs.

### Cleanup Behavior

- On success: current multidevs deleted immediately
- On failure: current multidevs preserved for investigation (URLs printed)
- Always: stale multidevs from prior runs with same prefix deleted; any CI-pattern multidev older than 72h deleted

## Known Issues

### Transient Platform Failures

These are Pantheon infrastructure issues, not script bugs:

- **postSchema 502**: Solr endpoint returns `502 OK` / `NOT UPLOADED`. Mitigated by retry logic (3 attempts, 60s apart) in both `run-tests.sh` and `test-field-mapping.sh`.
- **"Unable to install modules ... due to missing modules"**: Pantheon's Integrated Composer build occasionally doesn't include modules in the build artifact. Retrying usually resolves.
- **"The operation failed to complete"**: `workflow:wait` timeout during multidev creation. Transient.

### Bash Arithmetic and set -e

`((VAR++))` returns exit code 1 when VAR is 0 (pre-increment value is falsy). With `set -e`, this kills the script. Use `VAR=$((VAR + 1))` instead throughout all CI scripts.

### drupal.org Mirror

The `mirror_do` job pushes branches to drupal.org. Feature branches may fail if the drupal.org remote has diverged. This only matters for release branches (`8.x`).

### Pre-baked Dev Environment

The dev sites (`search-api-pantheon-d10.dev`, `search-api-pantheon-d11.dev`) have pre-configured Drupal content that multidevs inherit via database clone:

- **`field_test` content type** with 10 fields: `field_text_plain` (string), `field_text_long` (text_long), `field_integer` (integer), `field_boolean` (boolean), `field_email` (email), `field_link` (link), `field_decimal` (decimal), `field_date` (datetime/date), `field_datetime` (datetime/datetime), `field_list_text` (list_string)
- **Test node** titled "Field Mapping Test Node" with known values for all fields

This avoids creating content types, fields, and test content per-multidev. The Search API index configuration and Solr operations (schema post, indexing, querying) still happen per-multidev since each gets its own Solr core.

## Development Notes

- Solr version is configured in `pantheon.yml` under `search: version: 8|9`. The `create-multidev.sh` script writes this based on the `SOLR_VERSION` matrix variable.
- The module requires `search_api`, `search_api_solr`, and `search_api_pantheon` to be enabled. `devel_generate` (from `drupal/devel`) provides the `genc` command for test content.
- Schema is posted via `drush search-api-pantheon:postSchema`. For Solr 9, the config-set path is `/code/web/modules/contrib/search_api_solr/jump-start/solr9/config-set`.
