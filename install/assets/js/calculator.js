/**
 * ProspekwebCalc - Калькулятор себестоимости
 * UI калькулятора для 1С-Битрикс маркетплейса
 * @version 1.0.0
 */

(function(global) {
    'use strict';

    var ProspekwebCalc = {
        // API endpoints
        apiBase: '/local/tools/prospektweb.calc/',

        // Текущее состояние
        container: null,
        props: {},
        chain: [],
        calculators: [],
        groups: [],

        /**
         * Инициализация калькулятора в контейнере
         * @param {string} containerId - ID контейнера
         * @param {Object} props - Свойства инициализации
         */
        init: function(containerId, props) {
            if (containerId) {
                var container = document.getElementById(containerId);
                if (!container) {
                    console.error('ProspekwebCalc: Container not found:', containerId);
                    return;
                }
                this.container = container;
                this.props = props || {};
                this.chain = [];
                this.render();
                this.loadCalculators();
            } else {
                // Инициализация кнопки в админке
                this.initAdminButton();
            }
        },

        /**
         * Инициализация кнопки в админке
         */
        initAdminButton: function() {
            var self = this;

            // Ищем кнопку генерации ТП или другие кнопки в тулбаре
            var genBtn = document.getElementById('btn_sub_gen');
            if (!genBtn || !genBtn.parentNode) {
                return;
            }

            // Проверяем, не добавлена ли уже кнопка
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
         * Открытие диалога калькулятора
         */
        openCalculatorDialog: function() {
            var self = this;

            // Получаем выбранные ТП
            var checkboxes = document.querySelectorAll('input[name="SUB_ID[]"]:checked');
            var offerIds = [];
            for (var i = 0; i < checkboxes.length; i++) {
                offerIds.push(checkboxes[i].value);
            }

            if (offerIds.length === 0) {
                alert('Не выбраны торговые предложения');
                return;
            }

            // Создаём диалог
            var content = document.createElement('div');
            content.id = 'prospektweb_calc_container';
            content.className = 'prospektweb-calc-container';

            var dialog = new BX.CAdminDialog({
                title: 'Калькуляция себестоимости',
                content: content,
                width: 700,
                height: 550,
                resizable: true,
                draggable: true
            });

            dialog.Show();

            // Инициализируем калькулятор в контейнере
            this.init('prospektweb_calc_container', {
                offerIds: offerIds,
                dialog: dialog
            });
        },

        /**
         * Загрузка списка калькуляторов
         */
        loadCalculators: function() {
            var self = this;

            this.api('calculators.php', {}, function(data) {
                if (data && !data.error) {
                    self.groups = data.groups || [];
                    self.calculators = data.calculators || [];
                    self.renderCalculatorSelector();
                }
            });
        },

        /**
         * API запрос
         * @param {string} endpoint - Конечная точка API
         * @param {Object} params - Параметры запроса
         * @param {Function} callback - Функция обратного вызова
         */
        api: function(endpoint, params, callback) {
            var url = this.apiBase + endpoint;
            var queryParams = 'sessid=' + BX.bitrix_sessid();

            for (var key in params) {
                if (params.hasOwnProperty(key)) {
                    queryParams += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
                }
            }

            BX.ajax.get(url, queryParams, function(response) {
                var data = response;

                if (typeof data === 'string') {
                    var start = data.indexOf('{');
                    var end = data.lastIndexOf('}');
                    if (start !== -1 && end !== -1 && end > start) {
                        data = data.substring(start, end + 1);
                    }
                    try {
                        data = JSON.parse(data);
                    } catch (e) {
                        console.error('ProspekwebCalc: JSON parse error', e);
                        data = { error: 'parse_error' };
                    }
                }

                callback(data);
            });
        },

        /**
         * Отрисовка основного UI
         */
        render: function() {
            this.container.innerHTML = [
                '<div class="prospektweb-calc-progress" id="pwc_progress" style="display:none;">',
                '  <div class="prospektweb-calc-progress-text" id="pwc_progress_text">Обработано: 0 из 0</div>',
                '  <div class="prospektweb-calc-progress-bar">',
                '    <div class="prospektweb-calc-progress-fill" id="pwc_progress_fill" style="width:0%"></div>',
                '  </div>',
                '</div>',
                '<div class="prospektweb-calc-message prospektweb-calc-message-info" id="pwc_status">',
                '  Выбрано торговых предложений: <strong>' + (this.props.offerIds || []).length + '</strong>',
                '</div>',
                '<div class="prospektweb-calc-chain" id="pwc_chain">',
                '  <!-- Цепочка калькуляторов -->',
                '</div>',
                '<div class="prospektweb-calc-add-section" id="pwc_add_section">',
                '  <div class="prospektweb-calc-row">',
                '    <div class="prospektweb-calc-label">Группа:</div>',
                '    <div class="prospektweb-calc-control">',
                '      <select class="prospektweb-calc-select" id="pwc_group_select"></select>',
                '    </div>',
                '  </div>',
                '  <div class="prospektweb-calc-row">',
                '    <div class="prospektweb-calc-label">Калькулятор:</div>',
                '    <div class="prospektweb-calc-control">',
                '      <select class="prospektweb-calc-select" id="pwc_calc_select"></select>',
                '      <button type="button" class="prospektweb-calc-btn prospektweb-calc-btn-primary" id="pwc_add_btn" style="margin-top:5px;">',
                '        + Добавить',
                '      </button>',
                '    </div>',
                '  </div>',
                '</div>'
            ].join('\n');

            this.bindEvents();
        },

        /**
         * Привязка событий
         */
        bindEvents: function() {
            var self = this;

            var addBtn = document.getElementById('pwc_add_btn');
            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    self.addCalculatorToChain();
                });
            }

            var groupSelect = document.getElementById('pwc_group_select');
            if (groupSelect) {
                groupSelect.addEventListener('change', function() {
                    self.updateCalculatorSelect(this.value);
                });
            }
        },

        /**
         * Отрисовка селектора калькуляторов
         */
        renderCalculatorSelector: function() {
            var groupSelect = document.getElementById('pwc_group_select');
            var calcSelect = document.getElementById('pwc_calc_select');

            if (!groupSelect || !calcSelect) return;

            // Заполняем группы
            groupSelect.innerHTML = '';
            for (var i = 0; i < this.groups.length; i++) {
                var group = this.groups[i];
                var option = document.createElement('option');
                option.value = group.id;
                option.textContent = group.title;
                groupSelect.appendChild(option);
            }

            // Обновляем калькуляторы для первой группы
            if (this.groups.length > 0) {
                this.updateCalculatorSelect(this.groups[0].id);
            }
        },

        /**
         * Обновление списка калькуляторов по группе
         * @param {string} groupId - ID группы
         */
        updateCalculatorSelect: function(groupId) {
            var calcSelect = document.getElementById('pwc_calc_select');
            if (!calcSelect) return;

            calcSelect.innerHTML = '';

            for (var i = 0; i < this.calculators.length; i++) {
                var calc = this.calculators[i];
                if (calc.GROUP === groupId && !calc.IS_SYSTEM) {
                    var option = document.createElement('option');
                    option.value = calc.CODE;
                    option.textContent = calc.TITLE;
                    calcSelect.appendChild(option);
                }
            }
        },

        /**
         * Добавление калькулятора в цепочку
         */
        addCalculatorToChain: function() {
            var calcSelect = document.getElementById('pwc_calc_select');
            if (!calcSelect || !calcSelect.value) return;

            var code = calcSelect.value;
            var calc = this.findCalculatorByCode(code);

            if (calc) {
                this.chain.push({
                    code: code,
                    calc: calc,
                    options: {}
                });

                this.renderChain();
                this.checkAndAddPriceSettings();
            }
        },

        /**
         * Поиск калькулятора по коду
         * @param {string} code - Код калькулятора
         * @returns {Object|null}
         */
        findCalculatorByCode: function(code) {
            for (var i = 0; i < this.calculators.length; i++) {
                if (this.calculators[i].CODE === code) {
                    return this.calculators[i];
                }
            }
            return null;
        },

        /**
         * Отрисовка цепочки калькуляторов
         */
        renderChain: function() {
            var chainContainer = document.getElementById('pwc_chain');
            if (!chainContainer) return;

            chainContainer.innerHTML = '';

            for (var i = 0; i < this.chain.length; i++) {
                var item = this.chain[i];
                var accordion = this.createAccordion(item, i);
                chainContainer.appendChild(accordion);
            }
        },

        /**
         * Создание аккордеона для калькулятора
         * @param {Object} item - Элемент цепочки
         * @param {number} index - Индекс в цепочке
         * @returns {HTMLElement}
         */
        createAccordion: function(item, index) {
            var self = this;
            var calc = item.calc;
            var isSystem = calc.IS_SYSTEM === true;

            var accordion = document.createElement('div');
            accordion.className = 'prospektweb-calc-accordion' + (isSystem ? ' system' : '');
            accordion.setAttribute('data-index', index);

            var header = document.createElement('div');
            header.className = 'prospektweb-calc-accordion-header';

            var title = document.createElement('div');
            title.className = 'prospektweb-calc-accordion-title';
            title.textContent = calc.TITLE;

            var toggle = document.createElement('div');
            toggle.className = 'prospektweb-calc-accordion-toggle';
            toggle.innerHTML = '▼';

            var controls = document.createElement('div');
            controls.className = 'prospektweb-calc-chain-controls';

            if (!isSystem) {
                var removeBtn = document.createElement('button');
                removeBtn.className = 'prospektweb-calc-chain-btn prospektweb-calc-chain-btn-remove';
                removeBtn.textContent = '−';
                removeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.removeFromChain(index);
                });
                controls.appendChild(removeBtn);
            }

            header.appendChild(title);
            header.appendChild(controls);
            header.appendChild(toggle);

            var content = document.createElement('div');
            content.className = 'prospektweb-calc-accordion-content';
            this.renderOptions(calc.OPTIONS || [], content, index);

            accordion.appendChild(header);
            accordion.appendChild(content);

            header.addEventListener('click', function(e) {
                if (e.target.closest('.prospektweb-calc-chain-controls')) return;
                accordion.classList.toggle('collapsed');
            });

            return accordion;
        },

        /**
         * Отрисовка опций калькулятора
         * @param {Array} options - Массив опций
         * @param {HTMLElement} container - Контейнер для опций
         * @param {number} chainIndex - Индекс в цепочке
         */
        renderOptions: function(options, container, chainIndex) {
            if (!options || options.length === 0) {
                container.innerHTML = '<em>Нет дополнительных параметров</em>';
                return;
            }

            for (var i = 0; i < options.length; i++) {
                var opt = options[i];
                var row = this.createOptionRow(opt, chainIndex);
                container.appendChild(row);
            }
        },

        /**
         * Создание строки опции
         * @param {Object} opt - Опция
         * @param {number} chainIndex - Индекс в цепочке
         * @returns {HTMLElement}
         */
        createOptionRow: function(opt, chainIndex) {
            var row = document.createElement('div');
            row.className = 'prospektweb-calc-row';

            var label = document.createElement('div');
            label.className = 'prospektweb-calc-label';
            label.textContent = opt.label + ':';

            var control = document.createElement('div');
            control.className = 'prospektweb-calc-control';

            var input;
            var inputId = 'pwc_opt_' + chainIndex + '_' + opt.code;

            switch (opt.type) {
                case 'checkbox':
                    input = document.createElement('input');
                    input.type = 'checkbox';
                    input.id = inputId;
                    input.className = 'prospektweb-calc-checkbox';
                    input.checked = opt.default === 'Y' || opt.default === true;
                    break;

                case 'select':
                    input = document.createElement('select');
                    input.id = inputId;
                    input.className = 'prospektweb-calc-select';
                    if (opt.items) {
                        for (var j = 0; j < opt.items.length; j++) {
                            var item = opt.items[j];
                            var option = document.createElement('option');
                            option.value = item.value;
                            option.textContent = item.label;
                            if (item.value == opt.default) {
                                option.selected = true;
                            }
                            input.appendChild(option);
                        }
                    }
                    break;

                case 'number':
                    input = document.createElement('input');
                    input.type = 'number';
                    input.id = inputId;
                    input.className = 'prospektweb-calc-input';
                    input.value = opt.default || '';
                    if (opt.min !== undefined) input.min = opt.min;
                    if (opt.max !== undefined) input.max = opt.max;
                    if (opt.step !== undefined) input.step = opt.step;
                    break;

                default:
                    input = document.createElement('input');
                    input.type = 'text';
                    input.id = inputId;
                    input.className = 'prospektweb-calc-input';
                    input.value = opt.default || '';
            }

            control.appendChild(input);
            row.appendChild(label);
            row.appendChild(control);

            return row;
        },

        /**
         * Удаление калькулятора из цепочки
         * @param {number} index - Индекс в цепочке
         */
        removeFromChain: function(index) {
            if (index < 0 || index >= this.chain.length) return;

            var item = this.chain[index];
            if (item.calc.IS_SYSTEM) return;

            this.chain.splice(index, 1);
            this.renderChain();
            this.checkAndRemovePriceSettings();
        },

        /**
         * Проверка и добавление price_settings
         */
        checkAndAddPriceSettings: function() {
            var hasPriceChanger = false;
            var hasPriceSettings = false;

            for (var i = 0; i < this.chain.length; i++) {
                if (this.chain[i].calc.CAN_CHANGE_PRICE && !this.chain[i].calc.IS_SYSTEM) {
                    hasPriceChanger = true;
                }
                if (this.chain[i].code === 'price_settings') {
                    hasPriceSettings = true;
                }
            }

            if (hasPriceChanger && !hasPriceSettings) {
                var priceSettingsCalc = this.findCalculatorByCode('price_settings');
                if (priceSettingsCalc) {
                    this.chain.push({
                        code: 'price_settings',
                        calc: priceSettingsCalc,
                        options: {}
                    });
                    this.renderChain();
                }
            }
        },

        /**
         * Проверка и удаление price_settings
         */
        checkAndRemovePriceSettings: function() {
            var hasPriceChanger = false;

            for (var i = 0; i < this.chain.length; i++) {
                if (this.chain[i].calc.CAN_CHANGE_PRICE && !this.chain[i].calc.IS_SYSTEM) {
                    hasPriceChanger = true;
                    break;
                }
            }

            if (!hasPriceChanger) {
                for (var j = this.chain.length - 1; j >= 0; j--) {
                    if (this.chain[j].code === 'price_settings') {
                        this.chain.splice(j, 1);
                    }
                }
                this.renderChain();
            }
        }
    };

    // Экспорт в глобальную область
    global.ProspekwebCalc = ProspekwebCalc;

})(typeof window !== 'undefined' ? window : this);
