(function(window, document, BX) {
    'use strict';

    var config = window.ProspektwebCalcHeaderTabsConfig || {};
    var entityMap = config.entityMap || {};
    var iblockToEntity = {};
    Object.keys(entityMap).forEach(function(entityType) {
        var iblockId = parseInt(entityMap[entityType], 10);
        if (!isNaN(iblockId) && iblockId > 0) {
            iblockToEntity[iblockId] = entityType;
        }
    });
    var actionValue = config.actionValue || 'calc_use_in_header';
    var ajaxEndpoint = config.ajaxEndpoint;
    var actionTitle = (config.messages && config.messages.actionTitle) || 'Использовать в калькуляции';
    var sessid = config.sessid || (BX && BX.bitrix_sessid ? BX.bitrix_sessid() : '');

    var currentIblockId = getCurrentIblockId();
    var currentEntity = iblockToEntity[currentIblockId];

    if (!currentIblockId || !currentEntity) {
        return;
    }

    document.addEventListener('DOMContentLoaded', function() {
        addActionOptions();
        bindApplyButton();
        bindRowDelete();
    });

    function getCurrentIblockId() {
        var params = new URLSearchParams(window.location.search);
        var value = parseInt(params.get('IBLOCK_ID') || params.get('iblock_id') || params.get('PARENT') || '0', 10);
        return isNaN(value) ? 0 : value;
    }

    function addActionOptions() {
        var selectors = ['select[name="action"]', 'select[name="action_button"]', 'select[name="action_target"]'];
        selectors.forEach(function(selector) {
            var select = document.querySelector(selector);
            if (!select) {
                return;
            }
            if (select.querySelector('option[value="' + actionValue + '"]')) {
                return;
            }
            var option = document.createElement('option');
            option.value = actionValue;
            option.textContent = actionTitle;
            select.appendChild(option);
        });
    }

    function bindApplyButton() {
        var applyButton = document.getElementById('apply_button_control');
        if (!applyButton) {
            return;
        }

        applyButton.addEventListener('click', function(event) {
            var selectedAction = getSelectedAction();
            if (!selectedAction) {
                return;
            }

            if (selectedAction === actionValue) {
                event.preventDefault();
                event.stopPropagation();
                var selectedIds = getSelectedIds();
                if (!selectedIds.length) {
                    alert('Не выбраны элементы для добавления в калькуляцию');
                    return;
                }
                sendItems(selectedIds);
                return;
            }

            if (selectedAction === 'delete' || selectedAction === 'delete_all') {
                var idsForDelete = getSelectedIds();
                if (idsForDelete.length) {
                    markDeleted(idsForDelete);
                }
            }
        });
    }

    function getSelectedAction() {
        var selectors = ['select[name="action"]', 'select[name="action_button"]', 'select[name="action_target"]'];
        for (var i = 0; i < selectors.length; i++) {
            var select = document.querySelector(selectors[i]);
            if (select && select.value) {
                return select.value;
            }
        }
        return null;
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
