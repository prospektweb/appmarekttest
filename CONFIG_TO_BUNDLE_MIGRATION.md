# CONFIG to BUNDLE Architecture Migration

## Overview

This document describes the complete refactoring of the calculator system from CONFIG-based to BUNDLE-based architecture implemented in this PR.

## What Changed

### Terminology
- **Was**: "Config" / "Configuration" / `configId`
- **Now**: "Bundle" / "Сборка" / `bundleId`

### Architecture Philosophy
- **Was**: Mode-driven (NEW_CONFIG vs EXISTING_CONFIG)
- **Now**: State-driven (automatic decision based on bundle analysis)

## Key Features

### 1. Automatic Bundle Creation
When the calculator opens, the system:
1. Analyzes the BUNDLE state of all selected offers
2. Determines the appropriate action automatically
3. Creates a temporary bundle if needed

### 2. Temporary Bundle Rotation
- Temporary bundles are stored in a dedicated section
- Maximum number is configurable (default: 5)
- Oldest bundles are automatically deleted when limit is reached
- Rotation happens before creating new bundle

### 3. Conflict Detection
System detects three scenarios:

**Scenario A: Existing Bundle**
- All offers have the same bundle
- Action: Use existing bundle

**Scenario B: New Bundle**
- No offers have bundles
- Action: Create temporary bundle automatically

**Scenario C: Conflict**
- Offers have different bundles or mix of bundle/no-bundle
- Action: Show warning, require user confirmation
- After confirmation: Create new bundle with `force=1`

### 4. Bundle Finalization
- Temporary bundles can be "finalized"
- Finalization moves bundle from temp section to root
- Makes bundle permanent and reusable

## Technical Implementation

### New Files

#### lib/Calculator/BundleHandler.php (320 lines)
Complete bundle management system:
- `createTemporaryBundle()` - Creates bundle with rotation
- `rotateTemporaryBundles()` - Manages bundle limits
- `saveBundle()` - Saves bundle data
- `finalizeBundle()` - Makes bundle permanent
- `deleteBundle()` - Removes bundle and clears links
- `isTemporaryBundle()` - Checks bundle status
- `loadBundlesSummary()` - Gets bundle info for conflicts
- `buildPropertyValues()` - Maps linkedElements to properties

### Modified Files

#### lib/Calculator/InitPayloadService.php
**Added:**
- `analyzeBundles()` - Detects scenarios and conflicts
- `force` parameter support
- `requiresConfirmation` response
- `isTemporary` flag in bundle data

**Changed:**
- `loadBundle()` - Now uses JSON property instead of structure
- `loadOffers()` - Removed bundleId from offer data
- `collectLinkedElementIds()` - Updated mapping
- `loadBundleElements()` - Added iblock resolution

**Removed:**
- `determineMode()` - No longer needed
- `mode` from payload

#### lib/Calculator/SaveHandler.php
**Simplified from 317 to 40 lines:**
- Now delegates to BundleHandler
- Only validates bundleId
- Removed all business logic

#### tools/calculator_ajax.php
**Added handlers:**
- `handleSaveBundle()` - Saves bundle via BundleHandler
- `handleFinalizeBundle()` - Finalizes bundle

**Updated:**
- `handleGetInitData()` - Added force parameter

#### Configuration Files
- `default_option.php` - Added TEMP_BUNDLES_LIMIT, TEMP_BUNDLES_SECTION_ID
- `options.php` - Added UI for temp bundle settings
- `lang/ru/options.php` - Added translations

### Documentation
- `INTEGRATION.md` - Updated API documentation
- `IMPLEMENTATION_SUMMARY.md` - Updated architecture overview

## API Changes

### getInitData Endpoint

**Old:**
```
GET /calculator_ajax.php?action=getInitData&offerIds=123,456
Response: { mode: "NEW_BUNDLE" | "EXISTING_BUNDLE", bundle?: {...} }
```

**New:**
```
GET /calculator_ajax.php?action=getInitData&offerIds=123,456&force=0
Response: { bundle: {...} } or { requiresConfirmation: true, existingBundles: [...] }
```

### Save Endpoint

**Old:**
```
POST /calculator_ajax.php?action=save
Payload: { mode: "NEW_BUNDLE", configuration: {...}, offerUpdates: [...] }
```

**New:**
```
POST /calculator_ajax.php?action=saveBundle
Payload: { bundleId: 50, linkedElements: {...}, json: {...}, meta: {...} }
```

### New: Finalize Endpoint
```
POST /calculator_ajax.php?action=finalizeBundle&bundleId=50&name=My+Bundle
Response: { status: "ok", bundleId: 50, finalized: true }
```

## Data Structure Changes

### Bundle Object

**Old:**
```json
{
  "id": 50,
  "name": "Bundle Name",
  "code": "bundle_code",
  "structure": { /* old structure */ },
  "elements": { /* linked elements */ }
}
```

**New:**
```json
{
  "id": 50,
  "name": "Bundle Name",
  "code": "bundle_code",
  "isTemporary": false,
  "json": { /* flexible JSON data */ },
  "elements": {
    "calcConfig": [...],
    "calcSettings": [...],
    "materials": [...],
    "materialsVariants": [...],
    "operations": [...],
    "operationsVariants": [...],
    "equipment": [...],
    "details": [...],
    "detailsVariants": [...]
  }
}
```

### Offer Object

**Removed:**
- `bundleId` field (now only in bundle)

## React Application Changes Required

### 1. Remove Mode Handling
```typescript
// OLD - Remove this
if (payload.mode === 'NEW_BUNDLE') {
  // ...
} else if (payload.mode === 'EXISTING_BUNDLE') {
  // ...
}

// NEW - Mode is automatic
const bundle = payload.bundle;
```

### 2. Handle Conflict Warning
```typescript
// Check for conflict
if (payload.requiresConfirmation) {
  showConfirmDialog(payload.existingBundles);
  // On confirm, retry with force=1
  fetchInitData(offerIds, true);
}
```

### 3. Update Save Logic
```typescript
// OLD
postMessage({
  type: 'SAVE_REQUEST',
  payload: {
    mode: 'NEW_BUNDLE',
    configuration: {...},
    offerUpdates: [...]
  }
});

// NEW
postMessage({
  type: 'SAVE_BUNDLE_REQUEST',
  payload: {
    bundleId: bundle.id,
    linkedElements: {...},
    json: {...},
    meta: { name: 'Bundle Name' }
  }
});
```

### 4. Update Property Access
```typescript
// OLD
const data = bundle.structure;

// NEW
const data = bundle.json;
```

### 5. Check Temporary Status
```typescript
if (bundle.isTemporary) {
  // Show "Finalize" button
  showFinalizeButton();
}
```

## Configuration Setup

### Admin Panel
1. Navigate to module settings
2. Go to "Integration" tab
3. Configure:
   - **Temporary bundles limit**: Number of temp bundles to keep (default: 5)
   - **Temporary bundles section**: Section for temporary storage

### Creating Section
1. Go to CALC_BUNDLES iblock
2. Create new section named "Temporary" or "Временные"
3. Get section ID
4. Set in module settings

## Benefits

### For Developers
✅ Simpler code (no mode handling)
✅ Clear separation of concerns
✅ Automatic bundle management
✅ Better error handling

### For Users
✅ No decisions about "new" vs "existing"
✅ Clear warnings about conflicts
✅ Automatic cleanup of old bundles
✅ Easy finalization workflow

### For System
✅ Prevents bundle accumulation
✅ Automatic resource management
✅ Flexible data storage
✅ Better scalability

## Backward Compatibility

### What Still Works
✅ Existing bundles (JSON property compatible)
✅ Old save endpoint (still available)
✅ Existing bundle data structure

### What's Deprecated
⚠️ `mode` parameter (ignored if provided)
⚠️ `SAVE_REQUEST` message type (use SAVE_BUNDLE_REQUEST)
⚠️ `structure` property (use `json`)

## Testing Checklist

### Scenario A: Existing Bundle
- [ ] Open calculator with offers that have same bundle
- [ ] Verify bundle loads correctly
- [ ] Verify isTemporary flag is correct
- [ ] Save changes
- [ ] Verify bundle updates

### Scenario B: New Bundle
- [ ] Open calculator with offers that have no bundle
- [ ] Verify temporary bundle is created
- [ ] Verify bundleId is assigned to offers
- [ ] Save and finalize
- [ ] Verify bundle moves to permanent storage

### Scenario C: Conflict
- [ ] Open calculator with offers that have different bundles
- [ ] Verify warning is shown
- [ ] Cancel and verify no changes
- [ ] Confirm and verify new bundle is created
- [ ] Verify old bundles are unchanged

### Bundle Rotation
- [ ] Create more than limit temporary bundles
- [ ] Verify oldest bundles are deleted
- [ ] Verify limit is maintained

## Troubleshooting

### Bundle not created
- Check CALC_BUNDLES iblock is configured
- Check user has permissions
- Check logs for errors

### Rotation not working
- Verify TEMP_BUNDLES_LIMIT > 0
- Verify TEMP_BUNDLES_SECTION_ID is set
- Check section exists in iblock

### Conflict not detected
- Verify offers have BUNDLE property
- Check property values are set
- Verify bundleIds are different

## Performance Considerations

### Bundle Rotation
- Runs before creating new bundle
- Only queries if section ID is set
- Deletes in single operation

### Conflict Detection
- Single pass through offers
- Loads summaries only for conflicts
- No extra queries for scenario A/B

### Bundle Loading
- Uses ElementDataService for efficiency
- Batch loads linked elements
- Proper parent inclusion for variants

## Metrics

### Code Changes
- Files changed: 9
- Lines added: +794
- Lines removed: -430
- Net change: +364 lines

### Complexity Reduction
- SaveHandler: 317 → 40 lines (-87%)
- Clearer separation of concerns
- Single responsibility per class

## Credits

Implemented by: GitHub Copilot
Date: 2025-12-24
PR: copilot/replace-config-with-bundle
