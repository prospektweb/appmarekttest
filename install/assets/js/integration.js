/**
 * Интеграция Bitrix с React-калькулятором через postMessage
 * @module prospektweb.calc
 */

(function (window) {
    'use strict';

    /**
     * @typedef {Object} PwrtMessage
     * @property {'prospektweb.calc'|'bitrix'} source - Источник сообщения
     * @property {'bitrix'|'prospektweb.calc'} target - Получатель сообщения
     * @property {string} type - Тип сообщения
     * @property {string} [requestId] - ID запроса для связи запрос-ответ
     * @property {*} [payload] - Данные сообщения
     * @property {number} [timestamp] - Временная метка
     */

    const MODULE_SOURCE = 'bitrix';
    const MODULE_TARGET = 'prospektweb.calc';

    /**
     * Класс для интеграции с React-калькулятором
     */
    class CalcIntegration {
        constructor(config) {
            this.config = {
                iframe: config.iframe || null,
                iframeSelector: config.iframeSelector || '#calc-iframe',
                ajaxEndpoint: config.ajaxEndpoint || '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                offerIds: config.offerIds || [],
                siteId: config.siteId || '',
                sessid: config.sessid || '',
                onClose: config.onClose || null,
                onError: config.onError || null,
            };

            this.iframe = null;
            this.iframeWindow = null;
            this.isInitialized = false;
            this.hasUnsavedChanges = false;
            
            // Сохраняем ссылку на обработчик для корректного removeEventListener
            this.boundHandleMessage = this.handleMessage.bind(this);

            this.init();
        }

        /**
         * Инициализация
         */
        init() {
            // Поддержка передачи iframe напрямую или через селектор
            if (this.config.iframe) {
                this.iframe = this.config.iframe;
            } else {
                this.iframe = document.querySelector(this.config.iframeSelector);
            }
            
            if (!this.iframe) {
                console.error('[CalcIntegration] Iframe not found:', this.config.iframeSelector);
                return;
            }

            this.iframeWindow = this.iframe.contentWindow;
            this.setupMessageListener();
        }

        /**
         * Настройка обработчика postMessage
         */
        setupMessageListener() {
            window.addEventListener('message', this.boundHandleMessage);
        }

        /**
         * Обработка входящих сообщений
         * @param {MessageEvent} event
         */
        handleMessage(event) {
            // Проверка origin (в продакшене нужно проверять конкретный домен)
            // if (event.origin !== window.location.origin) {
            //     return;
            // }

            const message = event.data;

            // Валидация структуры сообщения
            if (!this.isValidMessage(message)) {
                return;
            }

            // Проверяем, что сообщение для нас
            if (message.target !== MODULE_SOURCE) {
                return;
            }

            console.log('[CalcIntegration] Received message:', message.type, message);

            // Маршрутизация по типу сообщения
            switch (message.type) {
                case 'READY':
                    this.handleReady(message);
                    break;

                case 'INIT_DONE':
                    this.handleInitDone(message);
                    break;

                case 'CALC_PREVIEW':
                    this.handleCalcPreview(message);
                    break;

                case 'SAVE_REQUEST':
                    this.handleSaveRequest(message);
                    break;

                case 'CLOSE_REQUEST':
                    this.handleCloseRequest(message);
                    break;

                case 'ERROR':
                    this.handleError(message);
                    break;

                default:
                    console.warn('[CalcIntegration] Unknown message type:', message.type);
            }
        }

        /**
         * Валидация сообщения
         * @param {*} message
         * @returns {boolean}
         */
        isValidMessage(message) {
            if (!message || typeof message !== 'object') {
                return false;
            }

            if (!message.source || !message.target || !message.type) {
                return false;
            }

            return true;
        }

        /**
         * Отправка сообщения в iframe
         * @param {string} type - Тип сообщения
         * @param {*} payload - Данные
         * @param {string} [requestId] - ID запроса
         */
        sendMessageToIframe(type, payload, requestId) {
            if (!this.iframeWindow) {
                console.error('[CalcIntegration] Iframe window not available');
                return;
            }

            const message = {
                source: MODULE_SOURCE,
                target: MODULE_TARGET,
                type: type,
                payload: payload,
                timestamp: Date.now(),
            };

            if (requestId) {
                message.requestId = requestId;
            }

            console.log('[CalcIntegration] Sending message:', type, message);
            this.iframeWindow.postMessage(message, '*');
        }

        /**
         * Обработка READY
         */
        async handleReady(message) {
            console.log('[CalcIntegration] Iframe is ready, fetching init data...');

            try {
                // Получаем данные для инициализации через AJAX
                const initData = await this.fetchInitData();

                // Отправляем INIT в iframe
                this.sendMessageToIframe('INIT', initData, message.requestId);
            } catch (error) {
                console.error('[CalcIntegration] Error fetching init data:', error);
                this.sendMessageToIframe('ERROR', {
                    message: 'Ошибка загрузки данных инициализации',
                    details: error.message,
                }, message.requestId);
            }
        }

        /**
         * Обработка INIT_DONE
         */
        handleInitDone(message) {
            console.log('[CalcIntegration] Initialization completed');
            this.isInitialized = true;
        }

        /**
         * Обработка CALC_PREVIEW
         */
        handleCalcPreview(message) {
            console.log('[CalcIntegration] Calculation preview received:', message.payload);
            this.hasUnsavedChanges = true;
            // Можно добавить дополнительную логику, например, показать превью
        }

        /**
         * Обработка SAVE_REQUEST
         */
        async handleSaveRequest(message) {
            console.log('[CalcIntegration] Save request received');

            try {
                // Валидация payload
                if (!message.payload) {
                    throw new Error('Отсутствуют данные для сохранения');
                }

                // Отправляем данные на сервер через AJAX
                const result = await this.saveData(message.payload);

                // Отправляем результат обратно в iframe
                this.sendMessageToIframe('SAVE_RESULT', result, message.requestId);

                if (result.status === 'ok' || result.status === 'partial') {
                    this.hasUnsavedChanges = false;
                }
            } catch (error) {
                console.error('[CalcIntegration] Error saving data:', error);
                this.sendMessageToIframe('SAVE_RESULT', {
                    status: 'error',
                    message: error.message,
                }, message.requestId);
            }
        }

        /**
         * Обработка CLOSE_REQUEST
         */
        handleCloseRequest(message) {
            console.log('[CalcIntegration] Close request received');

            if (this.hasUnsavedChanges) {
                const confirmed = confirm('Есть несохранённые изменения. Вы уверены, что хотите закрыть окно?');
                if (!confirmed) {
                    return;
                }
            }

            if (typeof this.config.onClose === 'function') {
                this.config.onClose();
            } else {
                // По умолчанию закрываем окно/попап
                if (window.BX && window.BX.PopupWindow) {
                    // Если используется BX.PopupWindow
                    const popup = window.BX.PopupWindow.getById('calc-popup');
                    if (popup) {
                        popup.close();
                    }
                } else {
                    window.close();
                }
            }
        }

        /**
         * Обработка ERROR
         */
        handleError(message) {
            console.error('[CalcIntegration] Error from iframe:', message.payload);

            if (typeof this.config.onError === 'function') {
                this.config.onError(message.payload);
            } else {
                alert('Ошибка: ' + (message.payload?.message || 'Неизвестная ошибка'));
            }
        }

        /**
         * Получение данных инициализации через AJAX
         * @returns {Promise<Object>}
         */
        async fetchInitData() {
            const url = this.config.ajaxEndpoint +
                '?action=getInitData' +
                '&offerIds=' + encodeURIComponent(this.config.offerIds.join(',')) +
                '&siteId=' + encodeURIComponent(this.config.siteId) +
                '&sessid=' + encodeURIComponent(this.config.sessid);

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || data.error || 'Ошибка получения данных');
            }

            return data.data;
        }

        /**
         * Сохранение данных через AJAX
         * @param {Object} payload
         * @returns {Promise<Object>}
         */
        async saveData(payload) {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('payload', JSON.stringify(payload));
            formData.append('sessid', this.config.sessid);

            const response = await fetch(this.config.ajaxEndpoint, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || data.error || 'Ошибка сохранения данных');
            }

            return data.data;
        }

        /**
         * Уничтожение интеграции
         */
        destroy() {
            window.removeEventListener('message', this.boundHandleMessage);
        }
    }

    // Экспорт в глобальную область
    window.ProspektwebCalcIntegration = CalcIntegration;

})(window);
