
document.addEventListener('DOMContentLoaded', () => {
    const isProfilePage = window.location.pathname.includes('profile.html');
    const $ = id => document.getElementById(id);
    let currentUser = null;
    let searchTimer = null;
    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[char]);
    }
    async function api(url, body = null) {
        try {
            const options = {
                method: body ? 'POST' : 'GET',
                credentials: 'same-origin'
            };
            if (body) options.body = body;
            const response = await fetch(url, options);
            const data = await response.json();
            if (!response.ok && !data.error) data.error = 'Ошибка сервера';
            return data;
        } catch (error) {
            console.error('API error:', error);
            return {
                success: false,
                auth: false,
                error: 'Не удалось подключиться к серверу'
            };
        }
    }
    function switchTab(tab) {
        const home = $('view-home');
        const catalog = $('view-catalog');
        if (!home || !catalog) return;
        const isCatalog = tab === 'catalog';
        home.style.display = isCatalog ? 'none' : 'block';
        catalog.style.display = isCatalog ? 'block' : 'none';
        if (isCatalog) {
            window.location.hash = 'catalog';
            loadCatalogOffers();
            loadCategories();
        } else {
            history.replaceState(null, null, 'index.html');
        }
    }
    function bindTabLinks(root = document) {
        if (isProfilePage) return;
        root.querySelectorAll('[data-tab]').forEach(element => {
            element.onclick = event => {
                event.preventDefault();
                switchTab(element.dataset.tab);
            };
        });
    }
    async function initialize() {
        await checkAuth();
        if (isProfilePage) {
            if (!currentUser) {
                window.location.href = 'index.html';
                return;
            }
            await loadCategories();
            await loadProfileData();
        } else {
            bindTabLinks();
            if (window.location.hash === '#catalog') {
                switchTab('catalog');
            } else {
                await loadCategories();
            }
        }
    }
    async function checkAuth() {
        const data = await api('api.php?action=check_auth');
        const nav = $('nav-auth');
        const createBtn = $('btn-create-trigger');
        currentUser = data.auth ? data : null;
        if (createBtn) {
            createBtn.style.display = data.auth ? 'inline-block' : 'none';
        }
        if (!nav) return;
        const baseLinks = isProfilePage
            ? `<a href="index.html" class="nav-link">Главная</a><a href="index.html#catalog" class="nav-link">Каталог</a>`
            : `<a href="#" class="nav-link" data-tab="home">Главная</a><a href="#catalog" class="nav-link" data-tab="catalog">Каталог</a>`;
        if (currentUser) {
            nav.innerHTML = `${baseLinks}<a href="profile.html" class="btn">Мой профиль</a><button id="btn-logout" class="btn btn-danger">Выйти</button>`;
            $('btn-logout').onclick = logout;
        } else {
            const authButtons = isProfilePage
                ? `<a href="index.html" class="btn">Войти</a>`
                : `<button class="btn" data-modal="loginModal">Войти</button><button class="btn btn-success" data-modal="registerModal">Регистрация</button>`;
            nav.innerHTML = baseLinks + authButtons;
            if (!isProfilePage) initModalTriggers(nav);
        }
        bindTabLinks(nav);
    }
    async function loadCategories() {
        const data = await api('api.php?action=get_categories');
        if (!data.success && data.error) {
            console.error(data.error);
            return;
        }
        const categories = data.categories || [];
        function fillSelect(select, firstOption = false) {
            if (!select) return;
            select.innerHTML = '';
            if (firstOption) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Выберите предмет/категорию';
                select.appendChild(option);
            }
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                select.appendChild(option);
            });
        }
        fillSelect($('select-category'), true);
        fillSelect($('edit-offer-category'));
        const filterCat = $('filter-category');
        if (filterCat) {
            const previousValue = filterCat.value || '0';
            filterCat.innerHTML = '';
            const allOption = document.createElement('option');
            allOption.value = '0';
            allOption.textContent = 'Все предметы';
            filterCat.appendChild(allOption);
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                filterCat.appendChild(option);
            });
            filterCat.value = previousValue;
        }
        ['filter-category', 'filter-type', 'filter-sort-by', 'filter-sort-dir'].forEach(id => {
            const element = $(id);
            if (element) element.onchange = loadCatalogOffers;
        });
        const search = $('filter-search');
        if (search) {
            search.oninput = () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadCatalogOffers, 300);
            };
        }
        const resetBtn = $('btn-reset-filters');
        if (resetBtn) {
            resetBtn.onclick = event => {
                event.preventDefault();
                if ($('filter-search')) $('filter-search').value = '';
                if ($('filter-type')) $('filter-type').value = '';
                if ($('filter-category')) $('filter-category').value = '0';
                if ($('filter-sort-by')) $('filter-sort-by').value = 'created_at';
                if ($('filter-sort-dir')) $('filter-sort-dir').value = 'DESC';
                loadCatalogOffers();
            };
        }
    }
    async function loadCatalogOffers() {
        const grid = $('catalog-grid');
        if (!grid) return;
        const params = new URLSearchParams({
            action: 'get_offers',
            search: ($('filter-search')?.value || '').trim(),
            type: $('filter-type')?.value || '',
            category_id: $('filter-category')?.value || '0',
            sort_by: $('filter-sort-by')?.value || 'created_at',
            sort_dir: $('filter-sort-dir')?.value || 'DESC'
        });
        grid.innerHTML = '<p>Загрузка заявок...</p>';
        const data = await api(`api.php?${params}`);
        if (!data.success) {
            grid.innerHTML = `<p class="error">${escapeHTML(data.error || 'Не удалось загрузить заявки')}</p>`;
            return;
        }
        const offers = data.offers || [];
        if (!offers.length) {
            grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#7f8c8d;">По вашему запросу ничего не найдено.</p>';
            return;
        }
        grid.innerHTML = offers.map(offer => {
            const typeBadge = offer.type === 'can_help'
                ? '<span class="badge" style="background:#e8f8f5;color:#27ae60;margin-left:6px;">Могу помочь</span>'
                : '<span class="badge" style="background:#fef5e7;color:#d35400;margin-left:6px;">Нужна помощь</span>';
            let action = '<p class="author">Войдите, чтобы откликнуться</p>';
            if (currentUser) {
                if (Number(currentUser.user_id) === Number(offer.user_id)) {
                    action = '<p class="status-wait">⏳ Ваша заявка активна</p>';
                } else if (Number(offer.already_replied) > 0) {
                    action = '<p class="reply-box" style="margin-top:10px;">✓ Вы уже откликнулись на эту заявку</p>';
                } else {
                    action = `<form class="reply-form" data-id="${Number(offer.id)}"><input type="text" name="message" placeholder="Напишите автору..." required maxlength="2000"><button type="submit" class="btn">Откликнуться</button></form>`;
                }
            }
            return `<div class="card"><div><span class="badge">${escapeHTML(offer.category_name)}</span>${typeBadge}</div><h2>${escapeHTML(offer.title)}</h2><p style="margin:10px 0;font-size:15px;">${escapeHTML(offer.description)}</p><p class="author" style="margin-bottom:2px;">Автор: ${escapeHTML(offer.first_name)} ${escapeHTML(offer.last_name)} (${escapeHTML(offer.group)})</p><p class="author" style="margin-top:0;font-size:11px;">🕒 Дата: ${escapeHTML(offer.created_at)}</p>${action}</div>`;
        }).join('');
        grid.querySelectorAll('.reply-form').forEach(form => {
            form.onsubmit = async event => {
                event.preventDefault();
                const submitButton = form.querySelector('button');
                submitButton.disabled = true;
                const fd = new FormData(form);
                fd.append('offer_id', form.dataset.id);
                const result = await api('api.php?action=create_request', fd);
                alert(result.success ? 'Вы успешно откликнулись!' : result.error || 'Ошибка отклика');
                if (result.success) {
                    await loadCatalogOffers();
                } else {
                    submitButton.disabled = false;
                }
            };
        });
    }
    initialize();
    async function loadProfileData() {
        const data = await api('api.php?action=get_profile_data');
        if (!data.success) {
            alert(data.error || 'Не удалось загрузить профиль');
            return;
        }
        if (data.user) {
            $('view-fullname').textContent = `${data.user.first_name} ${data.user.last_name}`;
            $('view-group').textContent = data.user.group;
            $('view-email').textContent = data.user.email;
            $('prof-first-name').value = data.user.first_name;
            $('prof-last-name').value = data.user.last_name;
            $('prof-group').value = data.user.group;
            $('prof-email').value = data.user.email;
        }
        /* Входящие отклики */
        const incoming = data.incoming_requests || [];
        if ($('incoming-section')) {
            $('incoming-section').style.display = incoming.length ? 'block' : 'none';
            if ($('incoming-grid')) {
                $('incoming-grid').innerHTML = incoming.map(request => `
                    <div class="card" style="border-left-color:#e67e22;">
                        <span class="badge" style="background:#ffeaa7;color:#d35400;">Заявка: ${escapeHTML(request.offer_title)}</span>
                        <p style="margin:10px 0;"><b>Сообщение:</b> ${escapeHTML(request.message)}</p>
                        <p class="author">От: ${escapeHTML(request.first_name)} ${escapeHTML(request.last_name)} (${escapeHTML(request.group)})</p>
                    </div>
                `).join('');
            }
        }
        /* Исходящие отклики */
        const outgoing = data.outgoing_requests || [];
        if ($('outgoing-section')) {
            $('outgoing-section').style.display = outgoing.length ? 'block' : 'none';
            if ($('outgoing-grid')) {
                $('outgoing-grid').innerHTML = outgoing.map(request => `
                    <div class="card" style="border-left-color:#2ecc71;">
                        <span class="badge">${escapeHTML(request.category_name)}</span>
                        <h2>${escapeHTML(request.offer_title)}</h2>
                        <p style="margin:10px 0;"><b>Ваше сообщение:</b> ${escapeHTML(request.message)}</p>
                        <p class="author">Автор: ${escapeHTML(request.first_name)} ${escapeHTML(request.last_name)} (${escapeHTML(request.group)})</p>
                        <p class="status-wait" style="color:#27ae60;">✓ Вы откликнулись (${request.offer_status === 'open' ? 'открыта' : 'закрыта'})</p>
                    </div>
                `).join('');
            }
        }
        /* Таблица собственных заявок */
        const tbody = $('my-offers-tbody');
        if (tbody) {
            const offers = data.my_offers || [];
            if (!offers.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#7f8c8d;">Вы еще не создали ни одной заявки.</td></tr>';
            } else {
                tbody.innerHTML = '';
                offers.forEach(offer => {
                    const tr = document.createElement('tr');
                    const values = [
                        offer.category_name,
                        offer.title,
                        offer.created_at || '—',
                        offer.updated_at || offer.created_at || '—',
                        offer.status === 'open' ? 'Активно' : 'Закрыто'
                    ];
                    values.forEach(value => {
                        const td = document.createElement('td');
                        td.textContent = value ?? '';
                        tr.appendChild(td);
                    });
                    tr.style.cursor = 'pointer';
                    tr.onclick = () => openEditOffer(offer);
                    tbody.appendChild(tr);
                });
            }
        }
    }
    /* Открытие окна редактирования заявки */
    function openEditOffer(offer) {
        if (!$('editOfferModal')) return;
        $('edit-offer-id').value = offer.id;
        $('edit-offer-category').value = offer.category_id;
        if ($('edit-offer-type')) $('edit-offer-type').value = offer.type || 'need_help';
        $('edit-offer-title').value = offer.title;
        $('edit-offer-desc').value = offer.description;
        $('edit-offer-status').value = offer.status;
        $('editOfferModal').style.display = 'block';
    }
    /* =========================================
       РЕДАКТИРОВАНИЕ ПРОФИЛЯ
    ========================================= */
    if ($('btn-edit-profile')) {
        const toggleEdit = edit => {
            if ($('profile-view-mode')) $('profile-view-mode').style.display = edit ? 'none' : 'grid';
            $('btn-edit-profile').style.display = edit ? 'none' : 'inline-block';
            if ($('form-profile-edit')) $('form-profile-edit').style.display = edit ? 'grid' : 'none';
            if (!edit) loadProfileData();
        };
        $('btn-edit-profile').onclick = () => toggleEdit(true);
        if ($('btn-cancel-profile')) {
            $('btn-cancel-profile').onclick = () => toggleEdit(false);
        }
        if ($('form-profile-edit')) {
            $('form-profile-edit').onsubmit = async event => {
                event.preventDefault();
                const form = event.target;
                const button = form.querySelector('[type="submit"]');
                if (button) button.disabled = true;
                const result = await api('api.php?action=update_profile', new FormData(form));
                alert(result.success ? 'Профиль обновлён!' : result.error || 'Ошибка сохранения');
                if (button) button.disabled = false;
                if (result.success) {
                    await checkAuth();
                    toggleEdit(false);
                }
            };
        }
    }
    /* =========================================
       УДАЛЕНИЕ ЗАЯВКИ
    ========================================= */
    if ($('btn-delete-offer')) {
        $('btn-delete-offer').onclick = async () => {
            const id = $('edit-offer-id')?.value;
            if (!id) {
                alert('Заявка не выбрана');
                return;
            }
            if (!confirm('Удалить эту заявку вместе со всеми откликами?')) return;
            const fd = new FormData();
            fd.append('offer_id', id);
            const result = await api('api.php?action=delete_offer', fd);
            if (result.success) {
                if ($('editOfferModal')) $('editOfferModal').style.display = 'none';
                alert('Заявка удалена.');
                await loadProfileData();
            } else {
                alert(result.error || 'Ошибка удаления');
            }
        };
    }
    /* =========================================
       УНИВЕРСАЛЬНАЯ ОТПРАВКА ФОРМ
    ========================================= */
    function bindForm(id, url, onSuccess) {
        const form = $(id);
        if (!form) return;
        form.onsubmit = async event => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            if (button) button.disabled = true;
            const result = await api(url, new FormData(form));
            if (button) button.disabled = false;
            if (result.success) {
                form.reset();
                const modal = form.closest('.modal');
                if (modal) modal.style.display = 'none';
                if (typeof onSuccess === 'function') await onSuccess(result);
            } else {
                alert(result.error || 'Ошибка выполнения');
            }
        };
    }
    /* Регистрация */
    bindForm('form-register', 'api.php?action=register', async () => {
        alert('Регистрация прошла успешно!');
        await checkAuth();
        if (!isProfilePage) await loadCatalogOffers();
    });
    /* Авторизация */
    bindForm('form-login', 'api.php?action=login', async () => {
        alert('Вы успешно вошли!');
        await checkAuth();
        if (!isProfilePage) await loadCatalogOffers();
    });
    /* Создание заявки */
    bindForm('form-ticket', 'api.php?action=create_offer', async () => {
        alert('Заявка успешно создана!');
        switchTab('catalog');
    });
    /* Смена пароля */
    bindForm('form-change-password', 'api.php?action=change_password', () => {
        alert('Пароль успешно изменён!');
    });
    /* Редактирование заявки */
    bindForm('form-edit-offer', 'api.php?action=update_offer', async () => {
        alert('Заявка обновлена!');
        if ($('editOfferModal')) $('editOfferModal').style.display = 'none';
        await loadProfileData();
    });

    /* =========================================
       ВЫХОД ИЗ АККАУНТА
    ========================================= */
    async function logout() {
        const result = await api('api.php?action=logout');
        if (result.success) {
            currentUser = null;
            if (isProfilePage) {
                window.location.href = 'index.html';
            } else {
                await checkAuth();
                await loadCatalogOffers();
            }
        } else {
            alert(result.error || 'Ошибка выхода');
        }
    }
    /* =========================================
       МОДАЛЬНЫЕ ОКНА
    ========================================= */
    function initModalTriggers(root = document) {
        root.querySelectorAll('[data-modal]').forEach(button => {
            button.onclick = () => {
                const modal = $(button.dataset.modal);
                if (modal) modal.style.display = 'block';
            };
        });
    }
    document.querySelectorAll('.close').forEach(button => {
        button.onclick = event => {
            const modal = event.target.closest('.modal');
            if (modal) modal.style.display = 'none';
        };
    });
    window.addEventListener('click', event => {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });
    initModalTriggers();
    /* =========================================
       ДИНАМИЧЕСКАЯ ВАЛИДАЦИЯ РЕГИСТРАЦИИ
    ========================================= */
    const regForm = $('form-register');
    if (regForm) {
        const rules = {
            first_name: value => {
                if (!value.trim()) return [false, 'Введите имя'];
                if (!/^[а-яА-ЯёЁa-zA-Z\s-]+$/u.test(value)) return [false, '❌ Только буквы, пробел или дефис'];
                return [true, '✓ Имя корректно'];
            },
            last_name: value => {
                if (!value.trim()) return [false, 'Введите фамилию'];
                if (!/^[а-яА-ЯёЁa-zA-Z\s-]+$/u.test(value)) return [false, '❌ Только буквы, пробел или дефис'];
                return [true, '✓ Фамилия корректна'];
            },
            group: value => {
                if (!value.trim()) return [false, 'Укажите учебную группу'];
                if (!/^[а-яА-ЯёЁa-zA-Z0-9\s.-]+$/u.test(value)) return [false, '❌ Недопустимые символы'];
                return [true, '✓ Группа указана'];
            },
            email: value => {
                if (!value.trim()) return [false, 'Введите Email'];
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return [false, '❌ Некорректный формат Email'];
                return [true, '✓ Email корректный'];
            },
            password: value => {
                if (!value.length) return [false, 'Придумайте пароль'];
                if (value.length < 6) return [false, `❌ Осталось ещё ${6 - value.length} симв.`];
                return [true, '✓ Надёжная длина пароля'];
            }
        };
        function checkInput(input) {
            const rule = rules[input.name];
            if (!rule) return true;
            const [valid, message] = rule(input.value);
            input.classList.toggle('input-valid', valid);
            input.classList.toggle('input-error', !valid);
            const hint = input.parentElement.querySelector('.field-hint');
            if (hint) {
                hint.textContent = message;
                hint.classList.toggle('valid', valid);
            }
            return valid;
        }
        regForm.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', () => checkInput(input));
            input.addEventListener('blur', () => checkInput(input));
        });
        regForm.addEventListener('submit', event => {
            const inputs = [...regForm.querySelectorAll('input')];
            const valid = inputs.every(input => checkInput(input));
            if (!valid) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }
});
