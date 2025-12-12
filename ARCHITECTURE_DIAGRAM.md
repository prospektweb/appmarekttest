# Architecture Diagram: Bitrix-React Calculator Integration

## Overview
This diagram shows how the calculator admin page integrates React with Bitrix using postMessage.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         BITRIX ADMIN INTERFACE                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ User clicks "Калькулятор"
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  /bitrix/admin/prospektweb_calc_calculator.php?offer_ids=123,456        │
│  ─────────────────────────────────────────────────────────────────────  │
│  1. Check authorization ✓                                               │
│  2. Check permissions (edit_catalog) ✓                                  │
│  3. Validate offer_ids parameter ✓                                      │
│  4. Load integration.js                                                 │
│  5. Render <iframe src="/local/apps/prospektweb.calc/index.html">      │
│  6. new ProspektwebCalcIntegration({...config}) ✓✓✓                    │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                ┌───────────────────┴───────────────────┐
                ▼                                       ▼
┌──────────────────────────────┐    ┌──────────────────────────────────┐
│  ProspektwebCalcIntegration  │    │        React App (iframe)        │
│  /bitrix/js/prospektweb.calc/│    │  /local/apps/prospektweb.calc/   │
│        integration.js         │    │          index.html              │
│                              │    │                                  │
│  - Listen to postMessage     │    │  - Initialize React              │
│  - Handle READY              │    │  - Send READY postMessage        │
│  - Fetch init data (AJAX)    │    │  - Wait for INIT                 │
│  - Send INIT                 │    │  - Render calculator UI          │
│  - Handle SAVE_REQUEST       │    │  - Send SAVE_REQUEST             │
│  - Send SAVE_RESULT          │    │  - Display results               │
└──────────────────────────────┘    └──────────────────────────────────┘
                │                                       │
                │  ┌───────────────────────────────────┘
                │  │      postMessage API
                │  │      ─────────────────
                │  │  ┌──► READY
                │  └──┤
                │     ├──► INIT
                │     │
                │     ├──► SAVE_REQUEST
                │     │
                │     ├──► SAVE_RESULT
                │     │
                │     └──► CLOSE_REQUEST, ERROR
                │
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│              /bitrix/tools/prospektweb.calc/calculator_ajax.php         │
│  ─────────────────────────────────────────────────────────────────────  │
│  AJAX Endpoint                                                          │
│                                                                         │
│  GET ?action=getInitData&offerIds=123,456&siteId=s1&sessid=xxx         │
│    ├─► Check authorization                                             │
│    ├─► Check permissions                                               │
│    ├─► Validate CSRF token                                             │
│    ├─► Call InitPayloadService                                         │
│    └─► Return JSON with configs, catalogs, offers                      │
│                                                                         │
│  POST action=save&payload={...}&sessid=xxx                             │
│    ├─► Check authorization                                             │
│    ├─► Check permissions                                               │
│    ├─► Validate CSRF token                                             │
│    ├─► Call SaveHandler                                                │
│    └─► Return JSON with save results                                   │
└─────────────────────────────────────────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      BITRIX BACKEND SERVICES                            │
│  ─────────────────────────────────────────────────────────────────────  │
│  InitPayloadService                                                     │
│    ├─► Load configurations from CALC_CONFIG iblock                     │
│    ├─► Load materials from CALC_MATERIALS iblock                       │
│    ├─► Load operations from CALC_OPERATIONS iblock                     │
│    ├─► Load equipment from CALC_EQUIPMENT iblock                       │
│    ├─► Load offer details from catalog                                 │
│    └─► Prepare INIT payload                                            │
│                                                                         │
│  SaveHandler                                                            │
│    ├─► Parse save payload                                              │
│    ├─► Validate data                                                   │
│    ├─► Update offers in catalog                                        │
│    ├─► Save/update configuration in CALC_CONFIG                        │
│    └─► Return save results                                             │
└─────────────────────────────────────────────────────────────────────────┘
```

## Message Flow Sequence

```
Time    Bitrix Admin Page    Integration.js      React App (iframe)    AJAX Endpoint
──────  ──────────────────   ───────────────     ──────────────────    ─────────────
  │
  │  1. User opens page
  │     with offer_ids
  │         │
  ├─────────┤
  │         │
  │  2. Load HTML, JS, iframe
  │         │
  ├─────────┤
  │         │
  │  3. new ProspektwebCalcIntegration()
  │         │
  │         ├──────────────────────────────►
  │         │                                │
  │         │                         4. React loaded
  │         │                                │
  │         │  ◄────────────────────────────┤
  │         │         postMessage: READY     │
  │         │                                │
  │  5. Handle READY                         │
  │         │                                │
  │         ├────────────────────────────────────────────►
  │         │             GET getInitData                 │
  │         │                                             │
  │         │                                      6. Process request
  │         │                                        Check auth/perms
  │         │                                        Load data
  │         │                                             │
  │         │  ◄──────────────────────────────────────────┤
  │         │        JSON response: init data             │
  │         │                                             │
  │  7. Receive data                                      │
  │         │                                             │
  │         ├──────────────────────────────►
  │         │    postMessage: INIT           │
  │         │    with payload                │
  │         │                                │
  │         │                         8. Initialize UI
  │         │                            with data
  │         │                                │
  │         │  ◄────────────────────────────┤
  │         │   postMessage: INIT_DONE       │
  │         │                                │
  │  9. User works with calculator           │
  │         │                                │
  │         │  ◄────────────────────────────┤
  │         │   postMessage: SAVE_REQUEST    │
  │         │                                │
  │  10. Handle save                         │
  │         │                                │
  │         ├────────────────────────────────────────────►
  │         │            POST save                        │
  │         │         with payload                        │
  │         │                                             │
  │         │                                      11. Process save
  │         │                                         Validate
  │         │                                         Update catalog
  │         │                                         Save config
  │         │                                             │
  │         │  ◄──────────────────────────────────────────┤
  │         │      JSON response: save result             │
  │         │                                             │
  │  12. Receive result                                   │
  │         │                                             │
  │         ├──────────────────────────────►
  │         │  postMessage: SAVE_RESULT      │
  │         │  with status                   │
  │         │                                │
  │         │                         13. Display success
  │         │                                │
  │         │  ◄────────────────────────────┤
  │         │  postMessage: CLOSE_REQUEST    │
  │         │                                │
  │  14. Close window or redirect            │
  │         │                                │
  ▼         ▼                                ▼
```

## File Structure After Installation

```
/bitrix/
├── admin/
│   └── prospektweb_calc_calculator.php    ← Admin page (NEW!)
│
├── js/
│   └── prospektweb.calc/
│       ├── integration.js                  ← Integration class (MOVED from /local)
│       ├── calculator.js
│       └── config.php
│
├── css/
│   └── prospektweb.calc/
│       └── calculator.css                  ← Styles (MOVED from /local)
│
└── tools/
    └── prospektweb.calc/
        ├── calculator_ajax.php             ← AJAX endpoint (MOVED from /local)
        ├── calculator_config.php
        ├── calculators.php
        ├── elements.php
        ├── equipment.php
        ├── config.php
        └── calculate.php

/local/
└── apps/
    └── prospektweb.calc/
        ├── index.html                      ← React app (unchanged location)
        └── assets/
            ├── index-*.js
            └── index-*.css

/modules/
└── prospektweb.calc/
    ├── admin/
    │   └── calculator.php                  ← Source file for installation
    ├── lang/
    │   └── ru/
    │       └── admin/
    │           └── calculator.php          ← Localization (NEW!)
    ├── install/
    │   ├── index.php                       ← Updated installation logic
    │   └── assets/
    │       ├── js/
    │       │   └── integration.js          ← Updated default paths
    │       ├── css/
    │       └── app_dist/
    ├── lib/
    │   └── Calculator/
    │       ├── InitPayloadService.php
    │       └── SaveHandler.php
    └── tools/
        └── calculator_ajax.php
```

## Key Features

✅ **Automatic Integration** - No manual class instantiation needed  
✅ **Security** - Authorization, permissions, CSRF protection  
✅ **Standard Paths** - Follows Bitrix conventions  
✅ **Error Handling** - Localized error messages  
✅ **Scoped CSS** - No conflicts with admin interface  
✅ **Full Documentation** - Usage examples and guides  

## Access Methods

### 1. Direct URL
```
/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=123,456
```

### 2. From JavaScript
```javascript
function openCalculator(offerIds) {
    window.open(
        '/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=' + offerIds.join(','),
        '_blank',
        'width=1400,height=900'
    );
}
```

### 3. From Admin List (Group Action)
```php
$adminList->AddGroupActionTable(['calc' => 'Открыть калькулятор']);
```

### 4. From Product Card (Context Menu)
```php
$contextMenu[] = [
    'TEXT' => 'Открыть калькулятор',
    'LINK' => '/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=' . $offerId
];
```

## Implementation Status: ✅ COMPLETE

All requirements from the problem statement have been successfully implemented:
- ✅ Created admin/calculator.php page
- ✅ Automatic ProspektwebCalcIntegration instantiation
- ✅ Authorization and permission checks
- ✅ GET parameter handling for offer_ids
- ✅ iframe with React calculator
- ✅ Updated install/index.php to copy files correctly
- ✅ Changed paths to /bitrix/ directories
- ✅ Updated integration.js default paths
- ✅ Added Russian localization
- ✅ Fixed security issues
- ✅ Scoped CSS styles
- ✅ Comprehensive documentation
- ✅ CodeQL scan passed (0 vulnerabilities)
