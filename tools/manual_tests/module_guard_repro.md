# Module guard repro for calculator_ajax.php

This manual check demonstrates the JSON response when a required Bitrix module is unavailable.

1. Temporarily disable a module (e.g., rename `/bitrix/modules/iblock` or `/bitrix/modules/catalog` so `Loader::includeModule()` returns `false`).  
   **Reminder:** restore the directory name after the check.
2. Call the endpoint with an authenticated session:  
   `curl -X POST "https://<host>/local/tools/calculator_ajax.php?action=getInitData&offerIds=1&siteId=s1" \`  
   `  -H "X-Requested-With: XMLHttpRequest" \`  
   `  -H "Content-Type: application/json" \`  
   `  -b "PHPSESSID=<session>; BITRIX_SM_SID=<sid>"`
3. Expected result: HTTP 500 JSON payload without an HTML fatal page, for example:  
   `{"error":"Module error","message":"Требуется модуль Bitrix iblock"}`

