/**
 * ContentCart JavaScript API
 * Простая и надежная работа с корзиной через localStorage
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 * @version     4.0.0
 */

(function() {
    'use strict';

    /**
     * ContentCart класс для управления корзиной
     */
    class ContentCart {
        /**
         * Конструктор
         *
         * @param {Object} options Настройки
         */
        constructor(options = {}) {
            this.storageKey = 'contentcart_items';
            this.apiUrl = options.apiUrl || '';
            this.token = options.token || '';
            this.ttlDays = options.ttlDays || 30;
            this.currency = options.currency || '';

            // Get language strings from Joomla
            const texts = window.Joomla && window.Joomla.getOptions
                ? window.Joomla.getOptions('ContentCartText')
                : window.ContentCartText || {};
            this.texts = texts;

            // Инициализация
            this.init();
        }

        /**
         * Инициализация
         */
        init() {
            // Проверка localStorage
            if (!this.isStorageAvailable()) {
                console.warn('[ContentCart] localStorage недоступен, fallback на POST формы');
                return;
            }

            // Очистка устаревших товаров
            this.clearExpired();

            // Обновление UI
            this.updateUI();
        }

        /**
         * Проверка доступности localStorage
         *
         * @returns {boolean}
         */
        isStorageAvailable() {
            try {
                const test = '__test__';
                localStorage.setItem(test, test);
                localStorage.removeItem(test);
                return true;
            } catch (e) {
                return false;
            }
        }

        /**
         * Получение корзины из localStorage
         *
         * @returns {Object}
         */
        getCart() {
            if (!this.isStorageAvailable()) {
                return { items: [] };
            }

            try {
                const data = localStorage.getItem(this.storageKey);
                if (!data) {
                    return { items: [] };
                }

                const cart = JSON.parse(data);

                // Валидация структуры
                if (!cart.items || !Array.isArray(cart.items)) {
                    return { items: [] };
                }

                return cart;
            } catch (e) {
                console.error('[ContentCart] Ошибка чтения корзины:', e);
                return { items: [] };
            }
        }

        /**
         * Сохранение корзины в localStorage
         *
         * @param {Object} cart
         */
        saveCart(cart) {
            if (!this.isStorageAvailable()) {
                return;
            }

            try {
                localStorage.setItem(this.storageKey, JSON.stringify(cart));
            } catch (e) {
                if (e.name === 'QuotaExceededError') {
                    alert(this.texts.quotaExceeded || 'Cart storage limit exceeded');
                }
                console.error('[ContentCart] Ошибка сохранения:', e);
            }
        }

        /**
         * Получение цены товара с сервера
         *
         * @param {number} articleId
         * @returns {Promise<number>}
         */
        async fetchPrice(articleId) {
            try {
                const url = `${this.apiUrl}&method=getPrice&article_id=${articleId}&${this.token}`;

                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    const text = await response.text();
                    console.error('[ContentCart] Response error:', text);
                    throw new Error(this.texts.priceRequestError || 'Price request error');
                }

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || this.texts.priceFetchError || 'Error fetching price');
                }

                // Joomla's AJAX format with plugin events: {success: true, data: [{price: X}]}
                // Event plugins return array of results, we take the first one
                let price = 0.0;

                if (result.data && Array.isArray(result.data) && result.data.length > 0) {
                    // Array format from event plugins
                    price = parseFloat(result.data[0].price || 0);
                } else if (result.data && result.data.price !== undefined) {
                    // Object format (fallback)
                    price = parseFloat(result.data.price);
                }

                return price;
            } catch (e) {
                console.error('[ContentCart] Ошибка получения цены:', e);
                return 0.0;
            }
        }

        /**
         * Добавление товара в корзину
         *
         * @param {number} articleId
         * @param {string} title
         * @param {string} link
         * @param {number} count
         * @returns {Promise<boolean>}
         */
        async addItem(articleId, title, link, count = 1) {
            try {
                // Получить цену с сервера (безопасность!)
                const price = await this.fetchPrice(articleId);

                const cart = this.getCart();

                // Проверить, есть ли товар в корзине
                const existingIndex = cart.items.findIndex(item => item.id === articleId);

                if (existingIndex !== -1) {
                    // Товар уже в корзине - увеличить количество
                    cart.items[existingIndex].count += count;
                    cart.items[existingIndex].price = price; // Обновить цену
                } else {
                    // Новый товар
                    cart.items.push({
                        id: articleId,
                        title: this.sanitize(title),
                        link: this.sanitize(link),
                        count: Math.max(1, Math.min(999, count)),
                        price: price,
                        added: Date.now()
                    });
                }

                this.saveCart(cart);
                this.updateUI();

                return true;
            } catch (e) {
                console.error('[ContentCart] Ошибка добавления товара:', e);
                return false;
            }
        }

        /**
         * Удаление товара из корзины
         *
         * @param {number} articleId
         * @returns {boolean}
         */
        removeItem(articleId) {
            try {
                const cart = this.getCart();
                const initialLength = cart.items.length;

                cart.items = cart.items.filter(item => item.id !== articleId);

                if (cart.items.length < initialLength) {
                    this.saveCart(cart);
                    this.updateUI();
                    return true;
                }

                return false;
            } catch (e) {
                console.error('[ContentCart] Ошибка удаления товара:', e);
                return false;
            }
        }

        /**
         * Обновление количества товара
         *
         * @param {number} articleId
         * @param {number} newCount
         * @returns {boolean}
         */
        updateCount(articleId, newCount) {
            if (newCount < 1 || newCount > 999) {
                return false;
            }

            try {
                const cart = this.getCart();
                const item = cart.items.find(item => item.id === articleId);

                if (item) {
                    item.count = newCount;
                    this.saveCart(cart);
                    this.updateUI();
                    return true;
                }

                return false;
            } catch (e) {
                console.error('[ContentCart] Ошибка обновления количества:', e);
                return false;
            }
        }

        /**
         * Получить товар по ID
         *
         * @param {number} articleId
         * @returns {object|null} Item object or null if not found
         */
        getItemById(articleId) {
            try {
                const cart = this.getCart();
                return cart.items.find(item => item.id === articleId) || null;
            } catch (e) {
                console.error('[ContentCart] Ошибка получения товара:', e);
                return null;
            }
        }

        /**
         * Очистка корзины
         */
        clearCart() {
            if (!this.isStorageAvailable()) {
                return;
            }

            try {
                localStorage.removeItem(this.storageKey);
                this.updateUI();
            } catch (e) {
                console.error('[ContentCart] Ошибка очистки корзины:', e);
            }
        }

        /**
         * Очистка устаревших товаров (TTL)
         */
        clearExpired() {
            if (this.ttlDays <= 0) {
                return; // TTL отключен
            }

            try {
                const cart = this.getCart();
                if (cart.items.length === 0) {
                    return;
                }

                const now = Date.now();
                const maxAge = this.ttlDays * 24 * 60 * 60 * 1000; // дни в миллисекунды
                const initialLength = cart.items.length;

                cart.items = cart.items.filter(item => {
                    const age = now - (item.added || now);
                    return age < maxAge;
                });

                if (cart.items.length < initialLength) {
                    this.saveCart(cart);
                }
            } catch (e) {
                console.error('[ContentCart] Ошибка очистки устаревших товаров:', e);
            }
        }

        /**
         * Получение общего количества товаров
         *
         * @returns {number}
         */
        getTotalCount() {
            const cart = this.getCart();
            return cart.items.reduce((sum, item) => sum + item.count, 0);
        }

        /**
         * Получение общей суммы
         *
         * @returns {number}
         */
        getTotalPrice() {
            const cart = this.getCart();
            return cart.items.reduce((sum, item) => sum + (item.price * item.count), 0);
        }

        /**
         * Обновление UI (счетчики, суммы)
         */
        updateUI() {
            if (!this.isStorageAvailable()) {
                return;
            }

            const count = this.getTotalCount();
            const total = this.getTotalPrice();

            // Обновить счетчики
            document.querySelectorAll('[data-contentcart-count]').forEach(el => {
                el.textContent = count;
            });

            // Обновить суммы
            document.querySelectorAll('[data-contentcart-total]').forEach(el => {
                el.textContent = total.toFixed(2) + (this.currency ? ' ' + this.currency : '');
            });

            // Управление видимостью кнопок в модуле
            const gotoButton = document.getElementById('cart-goto-button');
            const emptyButton = document.getElementById('cart-empty-button');
            const itemsList = document.getElementById('cart-items-list');

            if (gotoButton && emptyButton) {
                if (count > 0) {
                    gotoButton.style.display = '';
                    emptyButton.style.display = 'none';
                    if (itemsList) {
                        itemsList.style.display = '';
                    }
                } else {
                    gotoButton.style.display = 'none';
                    emptyButton.style.display = '';
                    if (itemsList) {
                        itemsList.style.display = 'none';
                    }
                }
            }

            // Рендерить список товаров в модуле
            if (itemsList && count > 0) {
                this.renderModuleItemsList(itemsList);
            }
        }

        /**
         * Рендерить список товаров в модуле корзины
         *
         * @param {HTMLElement} container
         */
        renderModuleItemsList(container) {
            const cart = this.getCart();

            let html = '<ul class="jlcc-cart-items">';

            cart.items.forEach(item => {
                const itemTotal = item.price * item.count; // Общая сумма = цена × количество
                html += '<li class="jlcc-cart-item">';
                html += '<a href="' + this.sanitize(item.link) + '">' + this.sanitize(item.title) + '</a>';
                html += ' <span class="jlcc-item-count">x' + item.count + '</span>';
                if (item.price > 0 && this.currency) {
                    html += ' <span class="jlcc-item-price">' + itemTotal.toFixed(2) + ' ' + this.currency + '</span>';
                }
                html += '</li>';
            });

            html += '</ul>';

            container.innerHTML = html;
        }

        /**
         * Подготовка данных корзины для отправки заказа
         *
         * @param {HTMLFormElement} form
         */
        prepareOrderForm(form) {
            if (!this.isStorageAvailable()) {
                return; // Fallback на PHP сессию
            }

            const cart = this.getCart();

            // Создать или обновить скрытое поле с данными корзины
            let input = form.querySelector('input[name="cart_data"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cart_data';
                form.appendChild(input);
            }

            input.value = JSON.stringify(cart);
        }

        /**
         * Санитизация текста (защита от XSS)
         *
         * @param {string} text
         * @returns {string}
         */
        sanitize(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.textContent;
        }
    }

    // Экспорт в глобальную область
    window.ContentCart = ContentCart;

})();
