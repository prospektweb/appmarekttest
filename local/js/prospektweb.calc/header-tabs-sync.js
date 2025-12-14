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
            return;
        }

        if (isListPage) {
            console.log('8. Initializing list page');
            initListPage();
            bindRowDelete();
        } else {
            console.warn('Page is neither edit nor list page');
        }
        console.groupEnd();
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

    function addGridAction(grid) {
        console.log('addGridAction: Starting - grid:', !!grid);
        var dropdown = getActionDropdown(grid);
        if (!dropdown) {
            console.warn('addGridAction: Dropdown not found');
            return false;
        }
        console.log('addGridAction: Dropdown found:', dropdown);

        var items = parseDropdownItems(dropdown);
        console.log('addGridAction: Existing dropdown items:', items);

        var exists = items.some(function(item) { return item && item.VALUE === actionValue; });
        if (exists) {
            console.log('addGridAction: Action already exists in dropdown');
            return false;
        }

        items.push({ NAME: actionTitle, VALUE: actionValue });
        dropdown.setAttribute('data-items', JSON.stringify(items));
        console.log('addGridAction: Added new action to dropdown, refreshing...');
        refreshDropdown(dropdown, items);
        console.log('addGridAction: Successfully added action option');
        return true;
    }

    function getActionDropdown(grid) {
        var gridId = grid && typeof grid.getId === 'function' ? grid.getId() : null;
        console.log('getActionDropdown: gridId:', gridId);
        if (!gridId) {
            console.warn('getActionDropdown: Could not get gridId from grid');
            return null;
        }

        var selector = '.main-dropdown[data-name="action_button_' + gridId + '"]';
        console.log('getActionDropdown: Looking for dropdown with selector:', selector);
        var dropdown = document.querySelector(selector);
        console.log('getActionDropdown: Found dropdown:', !!dropdown);
        return dropdown;
    }

    function parseDropdownItems(dropdown) {
        var itemsRaw = dropdown ? dropdown.getAttribute('data-items') : '';
        if (!itemsRaw) {
            return [];
        }

        try {
            var parsed = JSON.parse(itemsRaw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn('[calc_header_tabs] unable to parse dropdown items', e);
            return [];
        }
    }

    function refreshDropdown(dropdown, items) {
        if (!dropdown) {
            return;
        }

        var dropdownInstance = dropdown.BX && (dropdown.BX.dropdown || dropdown.BX.MainDropdown || dropdown.BX.menu);
        if (dropdownInstance) {
            if (typeof dropdownInstance.setItems === 'function') {
                dropdownInstance.setItems(items);
                return;
            }
            if (typeof dropdownInstance.setData === 'function') {
                dropdownInstance.setData(items);
                return;
            }
            if (typeof dropdownInstance.updateItems === 'function') {
                dropdownInstance.updateItems(items);
                return;
            }
        }

        if (BX && BX.UI && BX.UI.Dropdown) {
            dropdown.dataset.items = JSON.stringify(items);
            if (!dropdown.dataset.initialized) {
                // Инициализируем выпадающий список, если он ещё не создан
                /* eslint-disable no-new */
                new BX.UI.Dropdown({
                    targetElement: dropdown,
                    items: items
                });
                /* eslint-enable no-new */
                dropdown.dataset.initialized = 'true';
            }
        } else {
            dropdown.dataset.items = JSON.stringify(items);
        }
    }

    function bindGridApply(grid) {
        var applyButtons = getApplyButtons(grid);
        applyButtons.forEach(function(button) {
            button.addEventListener('click', function(event) {
                var selectedAction = getSelectedGridAction(grid);
                handleGridAction(grid, selectedAction, event);
            });
        });

        if (BX && BX.Event && BX.Event.EventEmitter && typeof BX.Event.EventEmitter.subscribe === 'function') {
            var handler = function(event) {
                var targetGrid = event.getTarget ? event.getTarget() : null;
                if (targetGrid && typeof targetGrid.getId === 'function' && grid && grid.getId && grid.getId() !== targetGrid.getId()) {
                    return;
                }

                var actionId = extractActionFromEvent(event, grid);
                handleGridAction(grid, actionId, event);
            };

            BX.Event.EventEmitter.subscribe('Grid::onActionButtonClick', handler);
            BX.Event.EventEmitter.subscribe('grid:clickAction', handler);
        }
    }

    function getApplyButtons(grid) {
        var panel = grid && grid.getActionsPanel && grid.getActionsPanel().getPanel ? grid.getActionsPanel().getPanel() : null;
        var buttons = panel ? panel.querySelectorAll('.ui-btn[data-id="apply_button"]') : null;

        if (buttons && buttons.length) {
            return Array.prototype.slice.call(buttons);
        }

        return Array.prototype.slice.call(document.querySelectorAll('.ui-btn[data-id="apply_button"]'));
    }

    function getSelectedGridAction(grid) {
        var gridId = grid && typeof grid.getId === 'function' ? grid.getId() : null;
        if (!gridId) {
            return null;
        }

        var hiddenInput = document.querySelector('input[name="action_button_' + gridId + '"]');
        if (hiddenInput && hiddenInput.value) {
            return hiddenInput.value;
        }

        var dropdown = getActionDropdown(grid);
        if (dropdown) {
            var datasetValue = dropdown.dataset ? dropdown.dataset.value : null;
            if (datasetValue) {
                return datasetValue;
            }

            var attrValue = dropdown.getAttribute('data-value');
            if (attrValue) {
                return attrValue;
            }
        }

        return null;
    }

    function extractActionFromEvent(event, grid) {
        var data = event && typeof event.getData === 'function' ? event.getData() : (event && event.data) || {};
        if (Array.isArray(data)) {
            data = data[0] || {};
        }

        var actionId = data.action || data.actionId || data.value || null;

        if (!actionId && data.button && data.button.dataset) {
            actionId = data.button.dataset.action || data.button.dataset.id;
        }

        if (!actionId && data.button && data.button.getAttribute) {
            actionId = data.button.getAttribute('data-action') || data.button.getAttribute('data-id');
        }

        if (!actionId && grid) {
            actionId = getSelectedGridAction(grid);
        }

        return actionId;
    }

    function handleGridAction(grid, actionId, event) {
        if (!actionId) {
            return;
        }

        if (actionId === actionValue) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (event && typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }

            var selectedIds = getGridSelectedIds(grid);
            if (!selectedIds.length) {
                alert('Не выбраны элементы для добавления в калькуляцию');
                return;
            }

            sendItems(selectedIds);
            return;
        }

        if (actionId === 'delete' || actionId === 'delete_all') {
            var idsForDelete = getGridSelectedIds(grid);
            if (idsForDelete.length) {
                markDeleted(idsForDelete);
            }
        }
    }

    function getGridSelectedIds(grid) {
        if (grid && grid.getRows && grid.getRows().getSelectedIds) {
            var ids = grid.getRows().getSelectedIds();
            return Array.isArray(ids) ? ids : [];
        }

        return getSelectedIds();
    }

    function initListPage() {
        console.log('initListPage: Starting list page initialization');
        var grid = getGridInstance();
        if (grid) {
            console.log('initListPage: Grid instance found, adding action');
            var actionAdded = addGridAction(grid);
            console.log('initListPage: Action added result:', actionAdded);
            bindGridApply(grid);
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

        var container = document.querySelector('.adm-title-buttons')
            || document.querySelector('.adm-detail-toolbar-right')
            || document.querySelector('.adm-detail-toolbar');

        console.log('initEditPage: Button container found:', !!container);
        if (!container || container.querySelector('[data-role="calc-header-tabs-btn"]')) {
            if (!container) {
                console.warn('initEditPage: Container not found');
            } else {
                console.log('initEditPage: Button already exists');
            }
            return;
        }

        var button = document.createElement('a');
        button.className = 'adm-btn';
        button.dataset.role = 'calc-header-tabs-btn';
        button.href = '#';
        button.textContent = actionTitle;

        button.addEventListener('click', function(event) {
            event.preventDefault();
            sendItems([elementId]);
        });

        container.appendChild(button);
        console.log('initEditPage: Button successfully added to container');
    }

    function getSelectedIds() {
        var checkboxes = document.querySelectorAll('input[type="checkbox"][name="ID[]"]:checked');
        var ids = [];
        for (var i = 0; i < checkboxes.length; i++) {
            var id = parseInt(checkboxes[i].value, 10);
            if (!isNaN(id) && id > 0) {
                ids.push(id);
            }
        }
        return ids;
    }

    function sendItems(ids) {
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
            materialsVariants: [],
            operationsVariants: [],
            detailsVariants: []
        };

        if (!raw) {
            return empty;
        }

        try {
            var parsed = JSON.parse(raw);
            ['equipment', 'materialsVariants', 'operationsVariants', 'detailsVariants'].forEach(function(key) {
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
            case 'materialsVariants':
                return 'header-material-' + itemId;
            case 'operationsVariants':
                return 'header-operation-' + itemId;
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
