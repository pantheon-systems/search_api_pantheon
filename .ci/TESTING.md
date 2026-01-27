# search_api_pantheon Test Suite

Comprehensive testing scripts for validating search_api_pantheon functionality on Pantheon sites.

## Quick Start

Run all tests on a fresh Pantheon site:

```bash
# Simplest - uses all defaults (random site name, Drupal 11, "CMS Platform" org)
./.ci/run-all-tests.sh

# With specific site name
./.ci/run-all-tests.sh my-site-name

# With custom organization
./.ci/run-all-tests.sh my-site-name 11 my-org

# Full syntax
./.ci/run-all-tests.sh [SITE_NAME] [DRUPAL_VERSION] [TERMINUS_ORG]
```

**Parameters:**
- `SITE_NAME` (optional): Site name without environment suffix. Auto-generated as `test-sap-XXXXXXXX` if omitted.
- `DRUPAL_VERSION` (optional): 10 or 11. Default: 11
- `TERMINUS_ORG` (optional): Pantheon organization. Default: "CMS Platform" (uses `$TERMINUS_ORG` env var if set)

**What it does:**
1. Creates a fresh Drupal site via `ci.sh`
2. Installs and configures search_api_pantheon
3. Runs field mapping tests
4. Runs Solr query tests
5. Runs environment parity tests

## Individual Test Suites

### 1. Field Mapping Tests (`test-field-mapping.sh`)

Tests that all Drupal field types correctly map to Solr field types and preserve data accurately.

**Usage:**
```bash
./.ci/test-field-mapping.sh my-site-name
```

**What it tests:**
- Module installation and configuration
- Content type and field creation
- Search API index configuration
- Schema posting to Solr
- Field type mappings:
  - Text (plain and long)
  - Integer
  - Decimal
  - Boolean
  - Date and DateTime
  - List fields
  - Email
  - Link
- Special characters and Unicode
- Field searchability

**Requirements:**
- Fresh Drupal 11 site on Pantheon
- Site name without environment suffix

### 2. Solr Query Tests (`test-solr-queries-terminus.sh`)

Tests Solr query functionality through the Drupal interface.

**Usage:**
```bash
./.ci/test-solr-queries-terminus.sh my-site-name.dev
```

**What it tests:**
- Basic Solr connectivity
- Match-all queries
- Document counting
- Field-specific searches
- Sorting
- Pagination
- Filtered queries
- Schema access

**Requirements:**
- Site with search_api_pantheon already installed and configured
- At least one indexed document (recommended)

### 3. Environment Parity Tests (`test-environment-parity.sh`)

Tests that Solr configuration is correctly isolated across Dev, Test, and Live environments.

**Usage:**
```bash
./.ci/test-environment-parity.sh my-site-name
```

**What it tests:**
- Environment variable detection (Dev, Test, Live)
- Solr core isolation
- Schema consistency across environments
- Document isolation between environments

**Requirements:**
- Site with Test and Live environments created
- search_api_pantheon installed on all environments

## Test Requirements

### Prerequisites
- Terminus CLI installed and authenticated
- jq installed (`brew install jq` on macOS)
- Bash 3.2+ (macOS default) or Bash 4+ for environment parity tests
- Pantheon account with site creation permissions

### Site Requirements
The test scripts work from scratch and will:
1. Install modules via Composer
2. Configure Solr in `pantheon.yml`
3. Enable required modules
4. Create test content
5. Run validations

## CI Integration

### GitHub Actions

To integrate with GitHub Actions, create `.github/workflows/test.yml`:

```yaml
name: Test search_api_pantheon

on:
  push:
    branches: [ main, 8.x, 8.4.x ]
  pull_request:
    branches: [ main, 8.x, 8.4.x ]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - name: Install Terminus
        run: |
          curl -O https://raw.githubusercontent.com/pantheon-systems/terminus-installer/master/builds/installer.phar
          php installer.phar install

      - name: Authenticate Terminus
        run: terminus auth:login --machine-token=${{ secrets.PANTHEON_MACHINE_TOKEN }}

      - name: Run Tests
        run: ./.ci/run-all-tests.sh test-site-${{ github.run_id }}
```

### Local Development

Run tests during development:

```bash
# Test specific functionality
./.ci/test-field-mapping.sh my-dev-site

# Run full suite
./.ci/run-all-tests.sh my-dev-site
```

## Troubleshooting

### "Module installation failed"
- Ensure site has `pantheon.yml` with Solr configuration
- Check that site is in git mode
- Verify Composer is working in the local clone

### "Indexing failed"
- Ensure Solr is enabled via `terminus solr:enable SITE`
- Check that `pantheon.yml` has `search: version: 8`
- Verify modules are actually installed (not just in composer.json)

### "Bash version too old" (environment parity tests)
- macOS ships with Bash 3.2 which doesn't support associative arrays
- Install Bash 4+: `brew install bash`
- Run with newer bash: `/opt/homebrew/bin/bash ./test-environment-parity.sh`

### "PANTHEON_INDEX_TOKEN not set"
- This is a warning, not an error
- The token is optional in current implementations
- Tests will continue despite this warning

## Test Output

Each test suite provides:
- **Color-coded output**: Green (pass), Red (fail), Yellow (warning), Blue (info)
- **Test counts**: Number of tests passed/failed
- **Detailed errors**: Specific failure messages with expected vs actual values
- **Exit codes**: 0 for success, 1 for failure

## Contributing

When adding new tests:
1. Follow the existing test structure
2. Use the common logging functions (`log_info`, `log_success`, `log_error`)
3. Track test results with counters
4. Provide clear error messages
5. Document the test in this README

## Known Limitations

1. **Field Mapping Tests**:
   - Decimal field mapping may have issues (known Solr limitation)
   - Field-specific searches require full Solr field names

2. **Query Tests**:
   - Faceting not supported via drush command
   - Highlighting not supported via drush command

3. **Environment Parity Tests**:
   - Requires Bash 4+ for associative arrays
   - Requires all three environments (dev, test, live) to exist
