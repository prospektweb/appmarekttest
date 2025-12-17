(function(window, document, BX) {
    'use strict';

    console.group('HeaderTabsSync Debug');

    var config = window.ProspektwebCalcHeaderTabsConfig || {};
    console.log('1. Script loaded - ProspektwebCalcHeaderTabsConfig:', config);

    var entityMap = config.entityMap || {};
    var iblockToEntity = {};
    Object.keys(entityMap).forEach(function(entityType) {
        var iblockId = parseInt(entityMap[entityType], 10);
        if (!isNaN(iblockId) && iblockId > 0) {
            iblockToEntity[iblockId] = entityType;
        }
    });
    console.log('2. Built iblockToEntity mapping:', iblockToEntity);

    var actionValue = config.actionValue || 'calc_use_in_header';
    var ajaxEndpoint = config.ajaxEndpoint;
    var actionTitle = (config.messages && config.messages.actionTitle) || 'Использовать в калькуляции';
    var sessid = config.sessid || (BX && BX.bitrix_sessid ? BX.bitrix_sessid() : '');

    var currentIblockId = getCurrentIblockId();
    console.log('3. Current iblock ID:', currentIblockId);

    var currentEntity = iblockToEntity[currentIblockId];
    console.log('4. Current entity:', currentEntity);

    var isEditPage = (window.location.pathname || '').indexOf('iblock_element_edit.php') !== -1;
    var isListPage = (window.location.pathname || '').indexOf('iblock_list_admin.php') !== -1;
    console.log('5. Page checks - isEditPage:', isEditPage, ', isListPage:', isListPage);

    if (!currentIblockId || !currentEntity) {
        if (!currentIblockId) {
            console.warn('Early exit: currentIblockId is 0 or invalid');
        }
        if (!currentEntity) {
            console.warn('Early exit: currentEntity not found for iblockId', currentIblockId);
        }
        console.groupEnd();
        return;
    }

    console.log('6. Initialization proceeding - will attach DOMContentLoaded listener');

    document.addEventListener('DOMContentLoaded', function() {
        console.log('7. DOMContentLoaded event fired');
        if (isEditPage) {
            console.log('8. Initializing edit page');
            initEditPage();
            console.groupEnd();
            return;
        }

        if (isListPage) {
            console.log('8. Initializing list page');
            initListPage();
            bindRowDelete();
            console.groupEnd();
        } else {
            console.warn('Page is neither edit nor list page');
            console.groupEnd();
        }
    });

    function getCurrentIblockId() {
        var params = new URLSearchParams(window.location.search);
        var value = parseInt(params.get('IBLOCK_ID') || params.get('iblock_id') || params.get('PARENT') || '0', 10);
        if (!isNaN(value) && value > 0) {
            return value;
        }

        var hidden = document.querySelector('input[name="IBLOCK_ID"], input[name="iblock_id"], input[name="PARENT"]');
        var hiddenValue = hidden ? parseInt(hidden.value, 10) : 0;

        return isNaN(hiddenValue) ? 0 : hiddenValue;
    }

    function getCurrentElementId() {
        var params = new URLSearchParams(window.location.search);
        var value = parseInt(params.get('ID') || params.get('id') || '0', 10);

        if (!isNaN(value) && value > 0) {
            return value;
        }

        var hidden = document.querySelector('input[name="ID"], input[name="id"]');
        var hiddenValue = hidden ? parseInt(hidden.value, 10) : 0;

        return isNaN(hiddenValue) ? 0 : hiddenValue;
    }

    function getGridInstance() {
        console.log('getGridInstance: Checking for BX.Main.gridManager');
        if (!(BX && BX.Main && BX.Main.gridManager)) {
            console.warn('getGridInstance: BX.Main.gridManager not available');
            return null;
        }

        var gridId = null;
        var managerData = BX.Main.gridManager.data || [];
        if (Array.isArray(managerData) && managerData.length && managerData[0].id) {
            gridId = managerData[0].id;
            console.log('getGridInstance: Found gridId from gridManager.data:', gridId);
        }

        if (!gridId) {
            var gridNode = document.querySelector('[data-entity="main-grid"]');
            if (gridNode && gridNode.id) {
                gridId = gridNode.id.replace('grid_', '') || gridNode.id;
                console.log('getGridInstance: Found gridId from DOM node:', gridId);
            }
        }

        if (!gridId) {
            console.warn('getGridInstance: Could not determine gridId');
            return null;
        }

        if (typeof BX.Main.gridManager.getInstanceById === 'function') {
            var instance = BX.Main.gridManager.getInstanceById(gridId);
            console.log('getGridInstance: Got instance via getInstanceById:', !!instance);
            return instance;
        }

        if (typeof BX.Main.gridManager.getById === 'function') {
            var gridData = BX.Main.gridManager.getById(gridId);
            if (gridData && gridData.instance) {
                console.log('getGridInstance: Got instance via getById:', !!gridData.instance);
                return gridData.instance;
            }
        }

        console.warn('getGridInstance: No method available to get grid instance');
        return null;
    }

    function createListPageButton(grid) {
        console.log('createListPageButton: Starting - grid:', !!grid);
        
        // Find the "Всего:" cell in the panel
        var totalCell = document.querySelector('.main-grid-panel-total.main-grid-panel-cell');
        if (!totalCell) {
            console.warn('createListPageButton: Total cell not found');
            return false;
        }
        
        // Check if button already exists
        var existingCell = document.getElementById('calc-header-tabs-cell');
        if (existingCell) {
            console.log('createListPageButton: Button cell already exists');
            return false;
        }
        
        // Create new cell with button
        var buttonCell = document.createElement('td');
        buttonCell.className = 'main-grid-panel-cell main-grid-cell-left';
        buttonCell.id = 'calc-header-tabs-cell';
        
        var button = document.createElement('button');
        button.className = 'ui-btn ui-btn-primary ui-btn-sm';
        button.id = 'calc-use-in-header-btn';
        button.title = actionTitle;
        button.textContent = actionTitle;
        
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            
            var selectedIds = getGridSelectedIds(grid);
            if (!selectedIds.length) {
                alert('Не выбраны элементы для добавления в калькуляцию');
                return;
            }
            
            sendItems(selectedIds);
        });
        
        buttonCell.appendChild(button);
        
        // Insert after total cell
        totalCell.parentNode.insertBefore(buttonCell, totalCell.nextSibling);
        
        console.log('createListPageButton: Button successfully added');
        
        // Listen to grid selection changes for delete tracking
        if (grid && BX && BX.Event && BX.Event.EventEmitter) {
            BX.Event.EventEmitter.subscribe('Grid::onActionButtonClick', function(event) {
                var data = event && typeof event.getData === 'function' ? event.getData() : (event && event.data) || {};
                if (Array.isArray(data)) {
                    data = data[0] || {};
                }
                var actionId = data.action || data.actionId || data.value || null;
                
                if (actionId === 'delete' || actionId === 'delete_all') {
                    var idsForDelete = getGridSelectedIds(grid);
                    if (idsForDelete.length) {
                        markDeleted(idsForDelete);
                    }
                }
            });
        }
        
        return true;
    }

    function getGridSelectedIds(grid) {
        var ids = [];
        if (grid && grid.getRows && grid.getRows().getSelectedIds) {
            var rawIds = grid.getRows().getSelectedIds();
            if (Array.isArray(rawIds)) {
                for (var i = 0; i < rawIds.length; i++) {
                    var rawValue = String(rawIds[i]);
                    // Удаляем префикс E если есть
                    var cleanValue = rawValue.replace(/^E/i, '');
                    var id = parseInt(cleanValue, 10);
                    if (!isNaN(id) && id > 0) {
                        ids.push(id);
                    }
                }
            }
            return ids;
        }

        return getSelectedIds();
    }

    function getSkuIblockId() {
        console.log('getSkuIblockId: Starting SKU IBLOCK_ID detection');
        
        // Method 1: Look for hidden input OFFERS_IBLOCK_ID
        var offersInput = document.querySelector('input[name="OFFERS_IBLOCK_ID"]');
        if (offersInput && offersInput.value) {
            var skuId = parseInt(offersInput.value, 10);
            if (!isNaN(skuId) && skuId > 0 && iblockToEntity[skuId]) {
                console.log('getSkuIblockId: Found SKU IBLOCK_ID from OFFERS_IBLOCK_ID input:', skuId);
                return skuId;
            }
        }
        
        // Method 2: Check if config has skuIblockId
        if (config.skuIblockId) {
            var skuId = parseInt(config.skuIblockId, 10);
            if (!isNaN(skuId) && skuId > 0 && iblockToEntity[skuId]) {
                console.log('getSkuIblockId: Found SKU IBLOCK_ID from config:', skuId);
                return skuId;
            }
        }
        
        // Method 3: Look in entityMap for *Variants type related to current iblock
        // Try to find the most relevant Variants type based on the current entity
        var variantPriority = ['materialsVariants', 'operationsVariants', 'detailsVariants'];
        for (var i = 0; i < variantPriority.length; i++) {
            var entityType = variantPriority[i];
            if (entityMap[entityType]) {
                var skuId = parseInt(entityMap[entityType], 10);
                if (!isNaN(skuId) && skuId > 0 && iblockToEntity[skuId]) {
                    console.log('getSkuIblockId: Found SKU IBLOCK_ID from entityMap:', skuId, 'entityType:', entityType);
                    return skuId;
                }
            }
        }
        
        console.warn('getSkuIblockId: Could not determine SKU IBLOCK_ID');
        return 0;
    }

    function initSkuTable() {
        console.log('initSkuTable: Starting SKU table initialization');
        
        // Find SKU table by looking for elements with id containing 'tbl_iblock_sub_element_'
        var skuFooters = document.querySelectorAll('[id^="tbl_iblock_sub_element_"][id$="_footer"]');
        if (!skuFooters.length) {
            console.warn('initSkuTable: No SKU table footer found');
            return;
        }
        
        console.log('initSkuTable: Found', skuFooters.length, 'SKU table footer(s)');
        
        var skuIblockId = getSkuIblockId();
        if (!skuIblockId) {
            console.warn('initSkuTable: Could not determine SKU IBLOCK_ID');
            return;
        }
        
        var skuEntity = iblockToEntity[skuIblockId];
        if (!skuEntity) {
            console.warn('initSkuTable: SKU IBLOCK_ID', skuIblockId, 'not found in entityMap');
            return;
        }
        
        console.log('initSkuTable: Using SKU IBLOCK_ID:', skuIblockId, 'entity:', skuEntity);
        
        skuFooters.forEach(function(footer) {
            // Check if button already exists
            if (footer.querySelector('#calc-header-tabs-sku-wrap')) {
                console.log('initSkuTable: Button already exists in footer');
                return;
            }
            
            // Find the counter element
            var counter = footer.querySelector('.adm-table-counter');
            if (!counter) {
                console.warn('initSkuTable: Counter not found in footer');
                return;
            }
            
            // Create button wrapper
            var buttonWrap = document.createElement('span');
            buttonWrap.className = 'adm-btn-wrap';
            buttonWrap.id = 'calc-header-tabs-sku-wrap';
            
            var button = document.createElement('input');
            button.type = 'button';
            button.className = 'adm-btn';
            button.value = actionTitle;
            button.id = 'calc-use-in-header-sku-btn';
            
            button.addEventListener('click', function(event) {
                event.preventDefault();
                
                // Get selected checkboxes from SKU table
                // Extract table ID more safely
                var footerId = footer.id || '';
                var suffix = '_footer';
                var endsWithFooter = footerId.length > suffix.length && 
                                    footerId.lastIndexOf(suffix) === footerId.length - suffix.length;
                
                if (!footerId || !endsWithFooter) {
                    console.warn('initSkuTable: Invalid footer ID:', footerId);
                    return;
                }
                
                var tableId = footerId.substring(0, footerId.length - suffix.length);
                var table = document.getElementById(tableId);
                if (!table) {
                    console.warn('initSkuTable: SKU table not found:', tableId);
                    return;
                }
                
                // Ищем чекбоксы в SKU-таблице (name="ID[]" или name="SUB_ID[]")
                var checkboxes = table.querySelectorAll(
                    'input[type="checkbox"][name="ID[]"]:checked, ' +
                    'input[type="checkbox"][name="SUB_ID[]"]:checked'
                );
                var selectedIds = [];
                var rawValues = [];
                for (var i = 0; i < checkboxes.length; i++) {
                    var rawValue = checkboxes[i].value;
                    rawValues.push(rawValue);
                    
                    // Удаляем префикс E если есть (Bitrix добавляет E для элементов)
                    var cleanValue = rawValue.replace(/^E/i, '');
                    var id = parseInt(cleanValue, 10);
                    if (!isNaN(id) && id > 0) {
                        selectedIds.push(id);
                    }
                }
                
                console.log('[HeaderTabsSync] initSkuTable - selected items:', {
                    rawCheckboxValues: rawValues,
                    cleanedIds: selectedIds
                });
                
                if (!selectedIds.length) {
                    alert('Не выбраны элементы для добавления в калькуляцию');
                    return;
                }
                
                console.log('initSkuTable: Sending SKU items:', selectedIds, 'iblockId:', skuIblockId, 'entity:', skuEntity);
                sendSkuItems(selectedIds, skuIblockId, skuEntity);
            });
            
            buttonWrap.appendChild(button);
            counter.parentNode.insertBefore(buttonWrap, counter);
            
            console.log('initSkuTable: Button successfully added to SKU table footer');
        });
    }

    function sendSkuItems(ids, iblockId, entityType) {
        console.log('[HeaderTabsSync] Sending SKU items:', {
            cleanedIds: ids,
            iblockId: iblockId,
            entityType: entityType
        });
        
        var payload = {
            action: 'headerTabsAdd',
            iblockId: iblockId,
            entityType: entityType,
            itemIds: ids,
            sessid: sessid
        };

        var onError = function(message) {
            console.error('[calc_header_tabs] error', message);
            alert(message || 'Ошибка при добавлении элементов в калькуляцию');
        };

        var onSuccess = function(response) {
            if (!response || response.error) {
                onError(response && response.message);
                return;
            }

            var data = response.data || {};
            if (!data.items || !Array.isArray(data.items)) {
                onError('Некорректный ответ сервера');
                return;
            }

            updateLocalStorage(data.entityType || entityType, data.items);
            alert('Элементы добавлены в калькуляцию');
        };

        if (BX && BX.ajax && BX.ajax.post) {
            BX.ajax.post(ajaxEndpoint, payload, function(result) {
                try {
                    var parsed = JSON.parse(result);
                    onSuccess(parsed);
                } catch (e) {
                    onError(e.message);
                }
            });
        } else {
            fetch(ajaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: buildFormData(payload)
            })
                .then(function(res) { return res.json(); })
                .then(onSuccess)
                .catch(function(err) { onError(err.message); });
        }
    }

    function initListPage() {
        console.log('initListPage: Starting list page initialization');
        var grid = getGridInstance();
        if (grid) {
            console.log('initListPage: Grid instance found, adding button');
            var buttonAdded = createListPageButton(grid);
            console.log('initListPage: Button added result:', buttonAdded);
        } else {
            console.warn('initListPage: Grid instance not found');
        }
    }

    function initEditPage() {
        console.log('initEditPage: Starting edit page initialization');
        var elementId = getCurrentElementId();
        console.log('initEditPage: Element ID:', elementId);
        if (!elementId) {
            console.warn('initEditPage: Element ID not found');
            return;
        }

        // On edit page, we only initialize SKU table for parent entities
        // The button will appear in the SKU table footer, not in the main toolbar
        initSkuTable();
    }

    function getSelectedIds() {
        // Ищем чекбоксы в обычном списке (name="ID[]") и в SKU-таблице (name="SUB_ID[]")
        var checkboxes = document.querySelectorAll(
            'input[type="checkbox"][name="ID[]"]:checked, ' +
            'input[type="checkbox"][name="SUB_ID[]"]:checked'
        );
        
        var ids = [];
        var rawValues = [];
        for (var i = 0; i < checkboxes.length; i++) {
            var rawValue = checkboxes[i].value;
            rawValues.push(rawValue);
            
            // Удаляем префикс E если есть (Bitrix добавляет E для элементов)
            var cleanValue = rawValue.replace(/^E/i, '');
            var id = parseInt(cleanValue, 10);
            if (!isNaN(id) && id > 0) {
                ids.push(id);
            }
        }
        
        console.log('[HeaderTabsSync] getSelectedIds:', {
            rawCheckboxValues: rawValues,
            cleanedIds: ids
        });
        
        return ids;
    }

    function sendItems(ids) {
        console.log('[HeaderTabsSync] Sending items:', {
            cleanedIds: ids,
            iblockId: currentIblockId,
            entityType: currentEntity
        });
        
        var payload = {
            action: 'headerTabsAdd',
            iblockId: currentIblockId,
            entityType: currentEntity,
            itemIds: ids,
            sessid: sessid
        };

        var onError = function(message) {
            console.error('[calc_header_tabs] error', message);
            alert(message || 'Ошибка при добавлении элементов в калькуляцию');
        };

        var onSuccess = function(response) {
            if (!response || response.error) {
                onError(response && response.message);
                return;
            }

            var data = response.data || {};
            if (!data.items || !Array.isArray(data.items)) {
                onError('Некорректный ответ сервера');
                return;
            }

            updateLocalStorage(data.entityType || currentEntity, data.items);
            alert('Элементы добавлены в калькуляцию');
        };

        if (BX && BX.ajax && BX.ajax.post) {
            BX.ajax.post(ajaxEndpoint, payload, function(result) {
                try {
                    var parsed = JSON.parse(result);
                    onSuccess(parsed);
                } catch (e) {
                    onError(e.message);
                }
            });
        } else {
            fetch(ajaxEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: buildFormData(payload)
            })
                .then(function(res) { return res.json(); })
                .then(onSuccess)
                .catch(function(err) { onError(err.message); });
        }
    }

    function buildFormData(data) {
        var params = [];
        Object.keys(data).forEach(function(key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function(item) {
                    params.push(encodeURIComponent(key + '[]') + '=' + encodeURIComponent(item));
                });
            } else {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });
        return params.join('&');
    }

    function ensureStorage() {
        var raw = localStorage.getItem('calc_header_tabs');
        var empty = {
            equipment: [],
            materials: [],
            materialsVariants: [],
            operations: [],
            operationsVariants: [],
            details: [],
            detailsVariants: []
        };

        if (!raw) {
            return empty;
        }

        try {
            var parsed = JSON.parse(raw);
            ['equipment', 'materials', 'materialsVariants', 'operations', 'operationsVariants', 'details', 'detailsVariants'].forEach(function(key) {
                if (!Array.isArray(parsed[key])) {
                    parsed[key] = [];
                }
            });
            return parsed;
        } catch (e) {
            console.warn('[calc_header_tabs] reset storage due to parse error', e);
            return empty;
        }
    }

    function updateLocalStorage(entityType, items) {
        var storage = ensureStorage();
        var list = storage[entityType];
        if (!Array.isArray(list)) {
            list = storage[entityType] = [];
        }

        items.forEach(function(item) {
            var existingIndex = list.findIndex(function(exist) { return parseInt(exist.itemId, 10) === parseInt(item.itemId, 10); });
            if (existingIndex >= 0) {
                list[existingIndex] = Object.assign({}, list[existingIndex], item, { deleted: undefined });
            } else {
                list.push(Object.assign({}, item));
            }
        });

        localStorage.setItem('calc_header_tabs', JSON.stringify(storage));
    }

    function markDeleted(ids) {
        var storage = ensureStorage();
        var list = storage[currentEntity];
        var timestamp = Date.now();

        ids.forEach(function(id) {
            var existingIndex = Array.isArray(list)
                ? list.findIndex(function(item) { return parseInt(item.itemId, 10) === parseInt(id, 10); })
                : -1;

            if (existingIndex >= 0) {
                list[existingIndex].deleted = timestamp;
            } else {
                list.push({
                    id: buildHeaderId(currentEntity, id),
                    itemId: id,
                    deleted: timestamp
                });
            }
        });

        localStorage.setItem('calc_header_tabs', JSON.stringify(storage));
    }

    function buildHeaderId(entityType, itemId) {
        switch (entityType) {
            case 'materials':
                return 'header-material-' + itemId;
            case 'materialsVariants':
                return 'header-material-' + itemId;
            case 'operations':
                return 'header-operation-' + itemId;
            case 'operationsVariants':
                return 'header-operation-' + itemId;
            case 'details':
                return 'header-detail-' + itemId;
            case 'detailsVariants':
                return 'header-detail-' + itemId;
            case 'equipment':
                return 'header-equipment-' + itemId;
            default:
                return 'header-item-' + itemId;
        }
    }

    function bindRowDelete() {
        document.addEventListener('click', function(event) {
            var deleteControl = event.target && event.target.closest('.adm-list-table-icon-delete, .adm-btn-delete, a[data-action="delete"]');
            if (!deleteControl) {
                return;
            }

            var row = deleteControl.closest('tr[id^="tr_"]');
            if (!row) {
                return;
            }

            var idString = row.id.replace('tr_', '');
            var itemId = parseInt(idString, 10);
            if (isNaN(itemId) || itemId <= 0) {
                return;
            }

            markDeleted([itemId]);
        });
    }
})(window, document, window.BX || null);
