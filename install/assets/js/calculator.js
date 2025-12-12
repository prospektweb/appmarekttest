/**
 * ProspekwebCalc - Калькулятор себестоимости
 * Интеграция React-приложения через iframe + postMessage
 * @version 2.0.0
 */

var ProspekwebCalc = {
    // Пути
    appUrl: '/local/apps/prospektweb.calc/index.html',
    apiBase: '/bitrix/tools/prospektweb.calc/',
    cssPath: '/local/css/prospektweb.calc/calculator.css',

    loadCss: function(href) {
        if (document.querySelector('link[href="' + href + '"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.type = 'text/css';
        link.href = href;
        document.head.appendChild(link);
    },
    
    // Белый список разрешённых endpoints для безопасности
    allowedEndpoints: [
        'calculators.php',
        'config.php',
        'equipment.php',
        'elements.php',
        'calculator_config.php',
        'calculate.php',
        'save_result.php'
    ],
    
    // Константы
    DOM_STABILIZATION_DELAY: 50, // Задержка в мс для стабилизации DOM после AJAX-обновлений
    
    // Состояние
    dialog: null,
    iframe: null,
    messageHandler: null,
    observer: null,

    /**
     * Инициализация кнопки в админке
     */
    init: function(containerId, props) {
        this.loadCss(this.cssPath);
        if (!containerId) {
            this.initAdminButton();
            this.startObserver();
        }
    },

    /**
     * Инициализация кнопки в админке
     */
    initAdminButton: function() {
        var self = this;

        var genBtn = document.getElementById('btn_sub_gen');
        if (!genBtn || !genBtn.parentNode) {
            return;
        }

        if (document.getElementById('btn_prospektweb_calc')) {
            return;
        }

        var toolbar = genBtn.parentNode;

        var calcBtn = document.createElement('a');
        calcBtn.id = 'btn_prospektweb_calc';
        calcBtn.className = 'adm-btn';
        calcBtn.href = 'javascript:void(0)';
        calcBtn.title = 'Калькуляция себестоимости';
        calcBtn.textContent = 'Калькуляция';

        calcBtn.addEventListener('click', function() {
            self.openCalculatorDialog();
        });

        if (genBtn.nextSibling) {
            toolbar.insertBefore(calcBtn, genBtn.nextSibling);
        } else {
            toolbar.appendChild(calcBtn);
        }
    },

    /**
     * Запуск наблюдателя за изменениями DOM
     */
    startObserver: function() {
        var self = this;
        
        // Если уже запущен - не запускаем повторно
        if (this.observer) {
            return;
        }
        
        // Ищем контейнер таблицы ТП (tab_sub_list или adm-detail-content-wrap)
        var targetNode = document.getElementById('tab_sub_list') || 
                         document.querySelector('.adm-detail-content-wrap');
        
        if (!targetNode) {
            // Fallback: наблюдаем за body
            targetNode = document.body;
        }
        
        this.observer = new MutationObserver(function(mutations) {
            // Оптимизация: проверяем, есть ли изменения в добавленных/удалённых узлах
            var hasRelevantChanges = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0 || mutations[i].removedNodes.length > 0) {
                    hasRelevantChanges = true;
                    break;
                }
            }
            
            if (!hasRelevantChanges) {
                return;
            }
            
            // Проверяем, существует ли кнопка генерации и отсутствует ли наша кнопка
            var genBtn = document.getElementById('btn_sub_gen');
            var calcBtn = document.getElementById('btn_prospektweb_calc');
            
            if (genBtn && !calcBtn) {
                // Небольшая задержка, чтобы DOM успел стабилизироваться
                setTimeout(function() {
                    self.initAdminButton();
                }, self.DOM_STABILIZATION_DELAY);
            }
        });
        
        this.observer.observe(targetNode, {
            childList: true,
            subtree: true
        });
    },

    /**
     * Остановка наблюдателя за изменениями DOM
     */
    stopObserver: function() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    },

    /**
     * Получение полной информации о выбранных торговых предложениях
     */
    getSelectedOffers: function() {
        var checkboxes = document.querySelectorAll('input[name="SUB_ID[]"]:checked');
        var offers = [];
        var productId = this.getProductId();
        var iblockId = this.getIblockId();
        
        for (var i = 0; i < checkboxes.length; i++) {
            var checkbox = checkboxes[i];
            var id = parseInt(checkbox.value, 10);
            
            if (isNaN(id) || id <= 0) {
                continue;
            }
            
            // Находим строку таблицы для получения названия
            var row = checkbox.closest('tr');
            var name = 'ТП #' + id; // Значение по умолчанию
            
            if (row) {
                // Ищем ячейку с названием (обычно это вторая или третья колонка после чекбокса)
                var cells = row.querySelectorAll('td');
                for (var j = 0; j < cells.length; j++) {
                    var cell = cells[j];
                    // Пропускаем ячейку с чекбоксом и ячейки с кнопками/иконками
                    if (!cell.querySelector('input[type="checkbox"]') && 
                        !cell.querySelector('a.adm-btn-delete') &&
                        cell.textContent.trim().length > 0) {
                        name = cell.textContent.trim();
                        break;
                    }
                }
            }
            
            // Формируем URL для редактирования ТП
            var editUrl = '/bitrix/admin/cat_product_edit.php?IBLOCK_ID=' + iblockId + 
                         '&type=catalog&ID=' + productId + 
                         '&WF=Y&find_section_section=-1&SUB_ID=' + id;
            
            offers.push({
                id: id,
                name: name,
                editUrl: editUrl,
                productId: productId,
                iblockId: iblockId
            });
        }
        
        return offers;
    },

    /**
     * Открытие диалога с iframe
     */
    openCalculatorDialog: function() {
        this.loadCss(this.cssPath);
        var self = this;

        // Получаем выбранные ТП с полной информацией
        var offers = this.getSelectedOffers();

        if (offers.length === 0) {
            alert('Не выбраны торговые предложения');
            return;
        }

        // Создаём контейнер для iframe
        var container = document.createElement('div');
        container.style.width = '100%';
        container.style.height = '100%';
        container.style.overflow = 'hidden';

        // Создаём iframe
        var iframe = document.createElement('iframe');
        iframe.src = this.appUrl;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.style.display = 'block';
        
        container.appendChild(iframe);
        this.iframe = iframe;

        // Создаём диалог
        var dialog = new BX.CAdminDialog({
            title: 'Калькуляция себестоимости',
            content: container,
            width: 1400,
            height: 800,
            resizable: true,
            draggable: true
        });

        this.dialog = dialog;

        // Используем ProspektwebCalcIntegration для обработки postMessage
        iframe.onload = function() {
            // Проверяем доступность ProspektwebCalcIntegration
            if (typeof window.ProspektwebCalcIntegration === 'undefined') {
                console.error('[ProspekwebCalc] ProspektwebCalcIntegration not loaded');
                alert('Ошибка загрузки модуля интеграции');
                return;
            }

            // Создаём интеграцию с передачей iframe напрямую
            self.integration = new window.ProspektwebCalcIntegration({
                iframe: iframe,
                ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                offerIds: offers.map(function(o) { return o.id; }),
                siteId: BX.message('SITE_ID') || (typeof SITE_ID !== 'undefined' ? SITE_ID : 's1'),
                sessid: BX.bitrix_sessid(),
                onClose: function() {
                    self.closeDialog();
                },
                onError: function(error) {
                    console.error('[ProspekwebCalc] Calc error:', error);
                    alert('Ошибка калькулятора: ' + (error.message || 'Неизвестная ошибка'));
                }
            });
        };

        dialog.Show();
    },

    /**
     * Отправка сообщения в iframe
     * @deprecated Используется ProspektwebCalcIntegration
     */
    sendToIframe: function(message) {
        if (this.iframe && this.iframe.contentWindow) {
            // Отправляем в том же домене - безопасно использовать window.location.origin
            var targetOrigin = window.location.origin;
            this.iframe.contentWindow.postMessage(message, targetOrigin);
        }
    },

    /**
     * Обработка сообщений от iframe
     * @deprecated Используется ProspektwebCalcIntegration
     */
    handleMessage: function(event) {
        // Проверяем origin - принимаем только сообщения с того же домена
        if (event.origin !== window.location.origin) {
            return;
        }
        
        var data = event.data;
        
        if (!data || !data.type) {
            return;
        }

        switch (data.type) {
            case 'CALC_READY':
                console.log('Calculator ready');
                break;
                
            case 'CALC_CLOSE':
                this.closeDialog();
                break;
                
            case 'CALC_OPEN_OFFER':
                // Открываем ТП в новой вкладке браузера
                if (data.payload && data.payload.editUrl) {
                    window.open(data.payload.editUrl, '_blank');
                    console.log('Opening offer in new tab:', data.payload.id);
                }
                break;
                
            case 'CALC_REMOVE_OFFER':
                // Логирование удаления ТП из списка
                if (data.payload && data.payload.id) {
                    console.log('Offer removed from list:', data.payload.id);
                }
                break;
                
            case 'CALC_RESULT':
                this.handleCalculationResult(data.payload);
                break;
                
            case 'CALC_SAVE_CONFIG':
                this.saveConfiguration(data.payload);
                break;
                
            case 'CALC_API_REQUEST':
                this.proxyApiRequest(data.payload);
                break;
                
            case 'CALC_ERROR':
                console.error('Calculator error:', data.payload);
                break;
        }
    },

    /**
     * Закрытие диалога
     */
    closeDialog: function() {
        // Уничтожаем интеграцию если она существует
        if (this.integration && typeof this.integration.destroy === 'function') {
            this.integration.destroy();
            this.integration = null;
        }
        
        // Удаляем старый обработчик сообщений (для обратной совместимости)
        if (this.messageHandler) {
            window.removeEventListener('message', this.messageHandler);
            this.messageHandler = null;
        }
        
        if (this.dialog) {
            this.dialog.Close();
            this.dialog = null;
        }
        
        this.iframe = null;
    },

    /**
     * Обработка результата калькуляции
     * @deprecated Используется ProspektwebCalcIntegration
     */
    handleCalculationResult: function(result) {
        var self = this;
        
        // Отправляем результат на сервер
        BX.ajax.post(
            this.apiBase + 'save_result.php',
            {
                sessid: BX.bitrix_sessid(),
                result: JSON.stringify(result)
            },
            function(response) {
                try {
                    var data = JSON.parse(response);
                    if (data.success) {
                        self.sendToIframe({
                            type: 'BITRIX_SAVE_SUCCESS',
                            payload: data
                        });
                    } else {
                        self.sendToIframe({
                            type: 'BITRIX_SAVE_ERROR',
                            payload: data.error || 'Unknown error'
                        });
                    }
                } catch (e) {
                    self.sendToIframe({
                        type: 'BITRIX_SAVE_ERROR',
                        payload: 'Parse error'
                    });
                }
            },
            function(error) {
                // Обработка сетевых ошибок
                self.sendToIframe({
                    type: 'BITRIX_SAVE_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * Сохранение конфигурации
     * @deprecated Используется ProspektwebCalcIntegration
     */
    saveConfiguration: function(config) {
        var self = this;
        
        BX.ajax.post(
            this.apiBase + 'config.php',
            {
                sessid: BX.bitrix_sessid(),
                action: 'save',
                config: JSON.stringify(config)
            },
            function(response) {
                try {
                    var data = JSON.parse(response);
                    self.sendToIframe({
                        type: 'BITRIX_CONFIG_SAVED',
                        payload: data
                    });
                } catch (e) {
                    self.sendToIframe({
                        type: 'BITRIX_CONFIG_ERROR',
                        payload: 'Parse error'
                    });
                }
            },
            function(error) {
                // Обработка сетевых ошибок
                self.sendToIframe({
                    type: 'BITRIX_CONFIG_ERROR',
                    payload: 'Network error: ' + (error || 'Unknown error')
                });
            }
        );
    },

    /**
     * Проксирование API запросов
     * @deprecated Используется ProspektwebCalcIntegration
     */
    proxyApiRequest: function(request) {
        var self = this;
        
        // Валидация входных данных
        if (!request || typeof request.endpoint !== 'string') {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request ? request.requestId : null,
                    success: false,
                    error: 'Invalid request'
                }
            });
            return;
        }
        
        // Валидация HTTP метода
        var allowedMethods = ['GET', 'POST'];
        var method = request.method || 'GET';
        if (allowedMethods.indexOf(method.toUpperCase()) === -1) {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request.requestId,
                    success: false,
                    error: 'Invalid method'
                }
            });
            return;
        }
        
        // Проверяем, что endpoint в белом списке
        if (this.allowedEndpoints.indexOf(request.endpoint) === -1) {
            self.sendToIframe({
                type: 'BITRIX_API_RESPONSE',
                payload: {
                    requestId: request.requestId,
                    success: false,
                    error: 'Access denied'
                }
            });
            return;
        }
        
        // Создаём объект данных вручную для поддержки старых браузеров
        // ВАЖНО: sessid добавляется последним, чтобы предотвратить переопределение
        var data = {};
        if (request.data) {
            for (var key in request.data) {
                if (request.data.hasOwnProperty(key) && key !== 'sessid') {
                    data[key] = request.data[key];
                }
            }
        }
        // Добавляем sessid в конце, чтобы он не мог быть переопределён
        data.sessid = BX.bitrix_sessid();
        
        BX.ajax({
            method: method,
            url: this.apiBase + request.endpoint,
            data: data,
            dataType: 'json',
            onsuccess: function(data) {
                self.sendToIframe({
                    type: 'BITRIX_API_RESPONSE',
                    payload: {
                        requestId: request.requestId,
                        success: true,
                        data: data
                    }
                });
            },
            onfailure: function(error) {
                self.sendToIframe({
                    type: 'BITRIX_API_RESPONSE',
                    payload: {
                        requestId: request.requestId,
                        success: false,
                        error: error
                    }
                });
            }
        });
    },

    /**
     * Получение ID товара из URL
     */
    getProductId: function() {
        var match = window.location.search.match(/ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    },

    /**
     * Получение ID инфоблока из URL
     */
    getIblockId: function() {
        var match = window.location.search.match(/IBLOCK_ID=(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }
};

// Экспорт
if (typeof window !== 'undefined') {
    window.ProspekwebCalc = ProspekwebCalc;
}
