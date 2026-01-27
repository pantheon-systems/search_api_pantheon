# Multidev-Based Testing for search_api_pantheon

Fast, efficient testing using a stable site with temporary multidev environments.

## Quick Start

```bash
# Set your stable site name
export TEST_SITE=my-stable-site

# Run all tests
./.ci/run-multidev-tests.sh
```

## How It Works

Instead of creating a fresh site for each test run (slow), this approach:

1. **Uses a stable, pre-existing site** - Set via `TEST_SITE` environment variable
2. **Creates temporary multidev environments** - Isolated test environments
3. **Runs tests across multidevs** - Validates isolation and functionality
4. **Cleans up automatically** - Deletes multidevs when done

## Advantages Over Fresh Site Approach

| Aspect | Fresh Site | Multidev |
|--------|------------|----------|
| Setup time | ~5 minutes | ~30 seconds |
| Cost | New site each run | Reuse existing site |
| CI frequency | Limited | Run often |
| Cleanup | Manual site deletion | Automatic |
| Multidev limit | N/A | 10 per site |

## Prerequisites

### 1. Create a Stable Site

The site should have:
- Drupal installed (dev environment ready)
- search_api_pantheon installed and configured
- Solr enabled (`pantheon.yml` with `search: version: 8`)

**One-time setup:**
```bash
# Option A: Use existing site
export TEST_SITE=existing-site-name

# Option B: Create new stable site for testing
terminus site:create test-sap-stable "Test SAP Stable" "drupal-11-composer-managed"
terminus connection:set test-sap-stable.dev git
terminus local:clone test-sap-stable
cd ~/pantheon-local-copies/test-sap-stable
echo "search:" >> pantheon.yml
echo "  version: 8" >> pantheon.yml
composer require "pantheon-systems/search_api_pantheon:8.4.x-dev"
terminus solr:enable test-sap-stable
git commit -am 'Add search_api_pantheon and Solr'
git push
terminus workflow:wait test-sap-stable.dev
terminus drush test-sap-stable.dev si standard -y
terminus drush test-sap-stable.dev en search_api_pantheon -y
export TEST_SITE=test-sap-stable
```

### 2. Environment Variables

```bash
# Required
export TEST_SITE=my-stable-site

# Optional
export MULTIDEV_PREFIX=test  # Default: "test"
```

## Usage

### Run All Tests

```bash
TEST_SITE=my-site ./.ci/run-multidev-tests.sh
```

### Run Individual Test Suites

```bash
# Field mapping tests on a multidev
./.ci/test-field-mapping.sh my-site test-abc12

# Environment parity between two multidevs
./.ci/test-multidev-parity.sh my-site test-abc12 test-xyz89
```

## What Gets Tested

### Suite 1: Field Mapping Tests
- Runs on first multidev environment
- Tests all Drupal field types → Solr mappings
- Validates data preservation during indexing

### Suite 2: Solr Query Tests
- Runs on first multidev environment
- Tests search functionality
- Validates query responses

### Suite 3: Multidev Parity Tests
- Compares two multidev environments
- Validates Solr core isolation
- Tests that indexed content doesn't leak between multidevs

## Multidev Management

### Automatic Cleanup

The test runner automatically deletes created multidevs on exit (success or failure).

### Manual Cleanup

If the script is interrupted:

```bash
# List multidevs
terminus multidev:list my-site

# Delete specific multidev
terminus multidev:delete my-site.test-abc12 --delete-branch -y
```

### Multidev Naming

Format: `{PREFIX}-{5-char-random}`
- Example: `test-abc12`, `test-xyz89`
- Max length: 11 characters (Pantheon limit)
- Lowercase alphanumeric only

## CI Integration

### GitHub Actions

```yaml
name: Test search_api_pantheon (Multidev)

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

      - name: Run Multidev Tests
        env:
          TEST_SITE: test-sap-stable
        run: ./.ci/run-multidev-tests.sh
```

### Local Development

```bash
# Quick iteration during development
export TEST_SITE=my-dev-site

# Run tests repeatedly
./.ci/run-multidev-tests.sh
./.ci/run-multidev-tests.sh  # Fast second run!
```

## Troubleshooting

### "Site does not exist"
```
✗ ERROR: Site my-site does not exist
```
**Solution:** Verify `TEST_SITE` is set correctly and site exists:
```bash
terminus site:info $TEST_SITE
```

### "Failed to create multidev"
- **Cause:** May have hit 10 multidev limit
- **Solution:** Clean up old multidevs:
```bash
terminus multidev:list $TEST_SITE
terminus multidev:delete $TEST_SITE.old-multidev --delete-branch -y
```

### "Multidev name too long"
- **Cause:** `MULTIDEV_PREFIX` too long
- **Limit:** Max 11 chars total (prefix + hyphen + 5 random chars)
- **Solution:** Use shorter prefix (max 5 chars recommended)

### Modules not installed in multidev
- **Cause:** Multidev created from dev, which may not have modules
- **Solution:** Ensure dev environment has search_api_pantheon installed first

## Performance Comparison

**Fresh Site Approach (run-all-tests.sh):**
- Site creation: ~5 minutes
- Module installation: ~2 minutes
- Tests: ~10 minutes
- **Total: ~17 minutes**

**Multidev Approach (run-multidev-tests.sh):**
- Multidev creation: ~30 seconds
- Tests: ~10 minutes
- **Total: ~10.5 minutes**

**Savings: ~40% faster** ⚡

## Best Practices

1. **Use a dedicated stable site for testing**
   - Don't use production sites
   - Keep it updated with latest search_api_pantheon

2. **Monitor multidev count**
   - Pantheon limits: 10 multidevs per site
   - Clean up failed runs manually if needed

3. **Run in CI regularly**
   - Fast enough for every PR
   - Catches regressions early

4. **Local testing**
   - Iterate quickly during development
   - No need to create fresh sites

## Comparison with Original Approach

| Feature | Fresh Site | Multidev |
|---------|-----------|----------|
| **Speed** | 17 min | 10.5 min |
| **Setup** | Automatic | One-time |
| **Cleanup** | Manual | Automatic |
| **Cost** | High (many sites) | Low (one site) |
| **CI suitable** | Slow | Fast |
| **Local dev** | Wasteful | Efficient |

## See Also

- [Original Testing Documentation](./TESTING.md) - Fresh site approach
- [Pantheon Multidev Docs](https://pantheon.io/docs/multidev)
- [Terminus CLI Docs](https://pantheon.io/docs/terminus)
