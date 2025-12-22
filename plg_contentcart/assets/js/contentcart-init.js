/**
 * ContentCart Initialization and Event Handlers
 * Обработка событий DOM и инициализация корзины
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 * @version     4.0.0
 */

(function() {
    'use strict';

    // Дождаться загрузки DOM
    document.addEventListener('DOMContentLoaded', function() {
        // Получить опции из Joomla.getOptions()
        const options = window.Joomla && window.Joomla.getOptions
            ? window.Joomla.getOptions('ContentCartOptions')
            : window.ContentCartOptions;

        // Проверить наличие опций
        if (!options) {
            console.warn('[ContentCart] ContentCartOptions not defined');
            return;
        }

        // Инициализировать корзину
        const cart = new ContentCart(options);

        // Экспорт в глобальную область для доступа из других скриптов
        window.contentCartInstance = cart;

        // ========================================
        // Проверка успешного заказа и очистка корзины
        // ========================================
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('order_success') === '1') {
            cart.clearCart();

            // Удалить параметр из URL чтобы при обновлении страницы не очищать снова
            urlParams.delete('order_success');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }

        // ========================================
        // Рендеринг корзины из localStorage на странице корзины
        // ========================================
        const cartPage = document.getElementById('contentcart-page');

        if (cartPage) {
            const emptyMessage = document.getElementById('cart-empty-message');
            const cartFromStorage = document.getElementById('cart-from-storage');

            if (emptyMessage && cartFromStorage) {
                const cartData = cart.getCart();

                if (cartData.items && cartData.items.length > 0) {
                    // Скрыть сообщение "корзина пуста"
                    emptyMessage.style.display = 'none';

                    // Показать контейнер с товарами
                    cartFromStorage.style.display = 'block';

                    // Рендерить таблицу товаров
                    renderCartTable(cart, cartFromStorage, options);

                    // Показать форму заказа
                    const orderFormContainer = document.getElementById('order-form-container');
                    if (orderFormContainer) {
                        orderFormContainer.style.display = 'block';
                    }

                    // Заполнить скрытое поле cart_data перед отправкой формы
                    const orderForm = document.getElementById('contentcart-order-form');
                    const cartDataInput = document.getElementById('cart_data');
                    if (orderForm && cartDataInput) {
                        orderForm.addEventListener('submit', function(e) {
                            // Записать данные корзины в скрытое поле
                            cartDataInput.value = JSON.stringify(cartData);
                        });
                    }
                }
            }
        }

        // ========================================
        // Обработчик добавления в корзину
        // ========================================
        document.querySelectorAll('.jlcc-add-form').forEach(function(form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const button = form.querySelector('.jlcc-add-to-cart');
                if (!button) return;

                const articleId = parseInt(button.dataset.articleId);
                const title = button.dataset.title;
                const link = button.dataset.link;
                const countInput = form.querySelector('.jlcc-count');
                const count = countInput ? parseInt(countInput.value) || 1 : 1;
                const loader = form.querySelector('.jlcc-loader');

                // Показать loader
                button.disabled = true;
                if (loader) {
                    loader.style.display = 'inline-block';
                }

                try {
                    // Добавить товар
                    const success = await cart.addItem(articleId, title, link, count);

                    // Скрыть loader
                    if (loader) {
                        loader.style.display = 'none';
                    }

                    if (success) {
                        // Показать сообщение об успехе
                        showMessage('success', 'Товар добавлен в корзину');

                        // Обновить состояние кнопки (показать количество с редактором)
                        updateButtonState(button, cart);
                    } else {
                        button.disabled = false;
                        showMessage('error', 'Ошибка добавления товара');
                    }
                } catch (error) {
                    // Скрыть loader
                    if (loader) {
                        loader.style.display = 'none';
                    }
                    button.disabled = false;
                    showMessage('error', 'Ошибка добавления товара');
                    console.error('[ContentCart]', error);
                }
            });
        });

        // ========================================
        // Проверка состояния всех кнопок при загрузке страницы
        // ========================================
        document.querySelectorAll('.jlcc-add-to-cart').forEach(function(button) {
            updateButtonState(button, cart);
        });

        // ========================================
        // Обработчик удаления из корзины
        // ========================================
        document.querySelectorAll('.jlcc-remove-item').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                const articleId = parseInt(this.dataset.articleId);

                if (!confirm('Удалить товар из корзины?')) {
                    return;
                }

                const success = cart.removeItem(articleId);

                if (success) {
                    // Анимация удаления
                    const row = this.closest('tr');
                    if (row) {
                        row.classList.add('removing');
                        setTimeout(function() {
                            row.remove();
                            updateCartTotals();
                        }, 300);
                    }

                    showMessage('success', 'Товар удален из корзины');
                } else {
                    showMessage('error', 'Ошибка удаления товара');
                }
            });
        });

        // ========================================
        // Обработчик изменения количества
        // ========================================
        document.querySelectorAll('.jlcc-item-count').forEach(function(input) {
            input.addEventListener('change', function() {
                const articleId = parseInt(this.dataset.articleId);
                const newCount = parseInt(this.value);

                if (isNaN(newCount) || newCount < 1 || newCount > 999) {
                    showMessage('error', 'Недопустимое количество (1-999)');
                    return;
                }

                const success = cart.updateCount(articleId, newCount);

                if (success) {
                    updateCartTotals();
                    showMessage('success', 'Количество обновлено');
                } else {
                    showMessage('error', 'Ошибка обновления количества');
                }
            });
        });

        // ========================================
        // Обработчик отправки заказа
        // ========================================
        const orderForm = document.getElementById('contentcart-order-form');
        if (orderForm) {
            orderForm.addEventListener('submit', function(e) {
                // Подготовить данные корзины для отправки
                // (создает скрытое поле cart_data с JSON)
                cart.prepareOrderForm(this);

                // Форма отправится обычным способом
                // PHP получит данные из $_POST['cart_data']
            });
        }

        /**
         * Обновление итогов корзины
         */
        function updateCartTotals() {
            const cartData = cart.getCart();
            const total = cart.getTotalPrice();
            const count = cart.getTotalCount();

            // Обновить общую сумму
            document.querySelectorAll('[data-contentcart-total]').forEach(function(el) {
                el.textContent = total.toFixed(2) + (ContentCartOptions.currency ? ' ' + ContentCartOptions.currency : '');
            });

            // Обновить количество
            document.querySelectorAll('[data-contentcart-count]').forEach(function(el) {
                el.textContent = count;
            });

            // Обновить суммы по строкам
            cartData.items.forEach(function(item) {
                const rowTotal = item.price * item.count;
                const rowTotalElement = document.querySelector('[data-row-total="' + item.id + '"]');
                if (rowTotalElement) {
                    rowTotalElement.textContent = rowTotal.toFixed(2) + (ContentCartOptions.currency ? ' ' + ContentCartOptions.currency : '');
                }
            });
        }

        /**
         * Показать сообщение пользователю
         *
         * @param {string} type success|error|warning|info
         * @param {string} message
         */
        function showMessage(type, message) {
            // Использовать Joomla Messages API если доступно
            if (window.Joomla && window.Joomla.renderMessages) {
                const messages = {};
                messages[type === 'success' ? 'message' : type] = [message];
                Joomla.renderMessages(messages);
            } else {
                // Fallback - простое уведомление
                alert(message);
            }
        }

        /**
         * Обновить состояние кнопки "Добавить в корзину"
         * Если товар уже в корзине - использовать существующий счётчик количества
         *
         * @param {HTMLElement} button
         * @param {ContentCart} cart
         */
        function updateButtonState(button, cart) {
            const articleId = parseInt(button.dataset.articleId);
            const item = cart.getItemById(articleId);

            if (item) {
                // Товар уже в корзине
                const textInCart = button.dataset.textInCart || 'В корзине';
                const form = button.closest('form');

                if (form) {
                    // Найти существующий счётчик количества
                    const countInput = form.querySelector('.jlcc-count');

                    if (countInput) {
                        // Обновить значение счётчика из корзины
                        countInput.value = item.count;

                        // Изменить текст кнопки (БЕЗ количества - оно в счётчике)
                        button.textContent = textInCart;
                        button.classList.remove('jlcc-primary');
                        button.classList.add('jlcc-success');
                        button.disabled = false;

                        // Удалить старые обработчики (если есть)
                        const newCountInput = countInput.cloneNode(true);
                        countInput.parentNode.replaceChild(newCountInput, countInput);

                        // Добавить обработчик изменения количества в существующем счётчике
                        newCountInput.addEventListener('change', function(e) {
                            const newCount = parseInt(this.value);

                            if (newCount > 0 && newCount <= 999) {
                                const success = cart.updateCount(articleId, newCount);
                                if (success) {
                                    showMessage('success', 'Количество обновлено: ' + newCount);
                                    // Кнопка остаётся без изменений - количество только в счётчике
                                } else {
                                    showMessage('error', 'Ошибка обновления количества');
                                    this.value = item.count;
                                }
                            } else {
                                showMessage('error', 'Недопустимое количество (1-999)');
                                this.value = item.count;
                            }
                        });

                        // При клике на кнопку - фокус на счётчик
                        button.addEventListener('click', function(e) {
                            e.preventDefault();
                            newCountInput.focus();
                            newCountInput.select();
                        });
                    }
                }
            }
        }

        /**
         * Рендерить таблицу корзины из localStorage
         *
         * @param {ContentCart} cart
         * @param {HTMLElement} container
         * @param {Object} options
         */
        function renderCartTable(cart, container, options) {
            const cartData = cart.getCart();
            const using_price = options.currency && options.currency.length > 0;

            let html = '<p>Товары загружены из вашего браузера:</p>';
            html += '<table style="width:100%;"><thead><tr>';
            html += '<th>№</th><th>Наименование</th><th>Количество</th>';
            if (using_price) {
                html += '<th>Цена</th><th>Сумма</th>';
            }
            html += '<th></th></tr></thead><tbody>';

            let total = 0;
            cartData.items.forEach(function(item, index) {
                const itemSum = item.price * item.count;
                total += itemSum;

                html += '<tr class="order_item">';
                html += '<td>' + (index + 1) + '</td>';
                html += '<td><a href="' + item.link + '">' + item.title + '</a></td>';
                html += '<td><input class="jlcc-input jlcc-count jlcc-item-count-js" type="number" data-article-id="' + item.id + '" max="999" min="1" value="' + item.count + '" /></td>';

                if (using_price) {
                    html += '<td>' + item.price.toFixed(2) + ' ' + options.currency + '</td>';
                    html += '<td class="item-sum-js" data-article-id="' + item.id + '">' + itemSum.toFixed(2) + ' ' + options.currency + '</td>';
                }

                html += '<td><a href="#" class="jlcc-remove-item-js" data-article-id="' + item.id + '">Удалить</a></td>';
                html += '</tr>';
            });

            if (using_price) {
                html += '<tr class="order_total">';
                html += '<td colspan="4" style="text-align:right;"><b>Итого:&nbsp;</b></td>';
                html += '<td>' + total.toFixed(2) + ' ' + options.currency + '</td>';
                html += '<td></td>';
                html += '</tr>';
            }

            html += '</tbody></table>';
            html += '<p><em>Примечание: для отправки заказа необходимо обновить страницу, чтобы синхронизировать данные.</em></p>';

            container.innerHTML = html;

            // Добавить обработчики изменения количества
            container.querySelectorAll('.jlcc-item-count-js').forEach(function(input) {
                input.addEventListener('change', function(e) {
                    const articleId = parseInt(this.dataset.articleId);
                    const newCount = parseInt(this.value);

                    if (newCount > 0 && newCount <= 999) {
                        // Обновить количество в localStorage
                        const updated = cart.updateCount(articleId, newCount);

                        if (updated) {
                            // Обновить сумму по строке
                            const cartData = cart.getCart();
                            const item = cartData.items.find(i => i.id === articleId);
                            if (item) {
                                const itemSum = item.price * newCount;
                                const sumCell = container.querySelector('.item-sum-js[data-article-id="' + articleId + '"]');
                                if (sumCell) {
                                    sumCell.textContent = itemSum.toFixed(2) + ' ' + options.currency;
                                }
                            }

                            // Обновить итоговую сумму
                            let total = 0;
                            cartData.items.forEach(function(item) {
                                total += item.price * item.count;
                            });
                            const totalCell = container.parentElement.querySelector('.order_total td:nth-child(2)');
                            if (totalCell) {
                                totalCell.textContent = total.toFixed(2) + ' ' + options.currency;
                            }
                        }
                    } else {
                        // Вернуть предыдущее значение
                        const cartData = cart.getCart();
                        const item = cartData.items.find(i => i.id === articleId);
                        if (item) {
                            this.value = item.count;
                        }
                    }
                });
            });

            // Добавить обработчики удаления
            container.querySelectorAll('.jlcc-remove-item-js').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const articleId = parseInt(this.dataset.articleId);

                    if (confirm('Удалить товар из корзины?')) {
                        cart.removeItem(articleId);
                        // Перерендерить таблицу
                        renderCartTable(cart, container, options);

                        // Проверить, если корзина пустая - скрыть форму заказа
                        const cartData = cart.getCart();
                        if (!cartData.items || cartData.items.length === 0) {
                            const orderFormContainer = document.getElementById('order-form-container');
                            if (orderFormContainer) {
                                orderFormContainer.style.display = 'none';
                            }
                            const emptyMessage = document.getElementById('cart-empty-message');
                            if (emptyMessage) {
                                emptyMessage.style.display = 'block';
                            }
                            container.style.display = 'none';
                        }
                    }
                });
            });
        }
    });

})();
