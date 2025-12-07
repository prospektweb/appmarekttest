/**
 * ProspekwebCalc - Калькулятор себестоимости
 * Интеграция React-приложения через iframe + postMessage
 * @version 2.0.0
 */

var ProspekwebCalc = {
    // Пути
    appUrl: '/local/apps/prospektweb.calc/index.html',
    apiBase: '/local/tools/prospektweb.calc/',
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
    
    // Состояние
    dialog: null,
    iframe: null,
    messageHandler: null,

    /**
     * Инициализация кнопки в админке
     */
    init: function(containerId, props) {
        this.loadCss(this.cssPath);
        if (!containerId) {
            this.initAdminButton();
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
     * Открытие диалога с iframe
     */
    openCalculatorDialog: function() {
        this.loadCss(this.cssPath);
        var self = this;

        // Получаем выбранные ТП
        var checkboxes = document.querySelectorAll('input[name="SUB_ID[]"]:checked');
        var offerIds = [];
        for (var i = 0; i < checkboxes.length; i++) {
            var id = parseInt(checkboxes[i].value, 10);
            if (!isNaN(id) && id > 0) {
                offerIds.push(id);
            }
        }

        if (offerIds.length === 0) {
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

        // Обработчик сообщений от iframe
        this.messageHandler = function(event) {
            self.handleMessage(event);
        };
        window.addEventListener('message', this.messageHandler);

        // Когда iframe загрузится, отправляем инициализационные данные
        iframe.onload = function() {
            self.sendToIframe({
                type: 'BITRIX_INIT',
                payload: {
                    offerIds: offerIds,
                    apiBase: self.apiBase,
                    productId: self.getProductId(),
                    iblockId: self.getIblockId()
                }
            });
        };

        dialog.Show();
    },

    /**
     * Отправка сообщения в iframe
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
