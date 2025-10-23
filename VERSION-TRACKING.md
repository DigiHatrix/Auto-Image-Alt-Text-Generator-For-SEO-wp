# Version Tracking Guide

## Current Version: 1.1.0

## Where to Update Version Numbers

When releasing a new version, update these **3 files**:

### 1. Main Plugin File
**File:** `hs-auto-image-alt-text-generator-for-seo.php`

**Lines to update:**
- Line 14: `Version: 1.1.0` (in plugin header)
- Line 31: `define('AAT_VERSION', '1.1.0');`

### 2. Readme (WordPress.org)
**File:** `readme.txt`

**Lines to update:**
- Line 7: `Stable tag: 1.1.0`
- Changelog section: Add new version entry at the top

### 3. README (GitHub)
**File:** `README.md`

**Lines to update:**
- Update version badge if present
- Update changelog reference if applicable

---

## Semantic Versioning Guide

Follow [SemVer](https://semver.org/) format: **MAJOR.MINOR.PATCH**

### When to Increment:

#### MAJOR (X.0.0)
**Breaking changes** - Requires user action
- Database schema changes requiring migration
- Removed or renamed functions/features
- Changed API endpoints
- Incompatible with previous versions

**Example:** 1.1.0 → 2.0.0

#### MINOR (1.X.0)
**New features** - Backwards compatible
- New functionality added
- New settings or options
- New UI components
- Enhanced existing features

**Example:** 1.1.0 → 1.2.0

#### PATCH (1.1.X)
**Bug fixes only** - Backwards compatible
- Security patches
- Bug fixes
- Performance improvements
- Minor UI tweaks
- Documentation updates

**Example:** 1.1.0 → 1.1.1

---

## Version Update Checklist

Before releasing a new version:

- [ ] Update version in `hs-auto-image-alt-text-generator-for-seo.php` (header)
- [ ] Update `AAT_VERSION` constant
- [ ] Update `Stable tag` in `readme.txt`
- [ ] Add changelog entry in `readme.txt`
- [ ] Update `Tested up to` field if WordPress version changed
- [ ] Test plugin activation/deactivation
- [ ] Test all AJAX endpoints
- [ ] Check for PHP/WordPress errors
- [ ] Review linter output (no new errors)
- [ ] Test on a staging site first
- [ ] Create git tag: `git tag -a v1.1.0 -m "Version 1.1.0"`
- [ ] Push tag: `git push origin v1.1.0`

---

## Recent Changelog

### 1.1.0 - 2025-01-19
**Type:** Minor Release (New Features)

**Changes:**
- Enhanced: Plugin lifecycle tracking (activation, deactivation, reactivation, uninstall)
- Enhanced: Automatic site registration with central server
- Enhanced: Site ID preservation across reinstalls
- Enhanced: Developer analytics dashboard with comprehensive metrics
- Enhanced: Pro upgrade tracking via Stripe integration
- Enhanced: Event history tracking with version information
- Improved: Button styling and icon alignment
- Improved: Custom SVG icons for better cross-site compatibility
- Improved: UTC timezone consistency across all tracking
- Fixed: Site ID regeneration on reactivation
- Fixed: Plugin versioning in event logs
- Added: Rate limiting for AJAX endpoints (60 requests/minute)
- Added: Weekly heartbeat for site status updates
- Added: Welcome notice after plugin activation (dismissible, 7-day window)
- Added: Feedback request after 10 generations (dismissible, one-time)
- Added: Dedicated `uninstall.php` for reliable cleanup

**Files Modified:**
- `hs-auto-image-alt-text-generator-for-seo.php`
- `assets/admin-style.css`
- `readme.txt`
- `README.md`
- `uninstall.php` (new file)
- Multiple API files in `api/hs-auto-alt-text-generator-for-seo/`

**Database Changes:**
- Added `plugin_status` and `last_event` columns to `hs_aat_plugin_sites`
- Created `hs_aat_plugin_events` table
- Added `plugin_version` column to events table
- Merged `hs_aat_ai_usage` and `hs_aat_generation_requests` tables

---

### 1.0.0 - 2024-12-01
**Type:** Major Release (Initial Public Release)

**Changes:**
- Initial public release
- Bulk alt text generation
- Individual image generation
- Alt text viewer with grid and table views
- Search and filter functionality
- Free plan: 15 generations/month
- Pro plan: 100 generations/month

---

## Quick Version Update Commands

```bash
# Check current version
grep "Version:" hs-auto-image-alt-text-generator-for-seo.php
grep "AAT_VERSION" hs-auto-image-alt-text-generator-for-seo.php
grep "Stable tag:" readme.txt

# After updating, create git tag
git add .
git commit -m "Release version 1.2.0"
git tag -a v1.2.0 -m "Version 1.2.0"
git push origin main
git push origin v1.2.0
```

---

## Next Version Planning

### Ideas for 1.2.0 (Minor Release):
- [ ] Admin notices for welcome, warnings, errors
- [ ] Transient caching for API responses
- [ ] Plugin row meta links (Docs, Support)
- [ ] Settings export/import
- [ ] Bulk edit alt text from viewer
- [ ] Image preview in table view

### Ideas for 1.1.1 (Patch Release):
- [ ] Bug fixes only
- [ ] Performance optimizations
- [ ] Security patches

### Ideas for 2.0.0 (Major Release):
- [ ] Multisite support with network activation
- [ ] Custom AI prompt templates
- [ ] Bulk pricing tiers
- [ ] WooCommerce product image integration
- [ ] REST API for external integrations

