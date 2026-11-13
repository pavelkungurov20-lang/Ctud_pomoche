<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверка CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientToken = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (
        empty($clientToken) ||
        empty($sessionToken) ||
        !hash_equals($sessionToken, $clientToken)
    ) {
        response([
            'success' => false,
            'error' => 'Ошибка безопасности. Обновите страницу.'
        ], 403);
    }
}
$action = $_GET['action'] ?? '';
try {
    switch ($action) {

        // 1. Проверка авторизации
        case 'check_auth':
            if (isset($_SESSION['user_id'])) {
                response([
                    'auth' => true,
                    'user_id' => $_SESSION['user_id'],
                    'name' => $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
                    'group' => $_SESSION['group'],
                    'csrf_token' => $_SESSION['csrf_token']
                ]);
            }
            response([
                'auth' => false,
                'csrf_token' => $_SESSION['csrf_token']
            ]);

        // 2. Регистрация
        case 'register':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response(['success' => false, 'error' => 'Неверный метод запроса'], 405);
            }
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $group = trim($_POST['group'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            if (
                !$first_name ||
                !$last_name ||
                !$group ||
                !$email ||
                !$password
            ) {
                response([
                    'success' => false,
                    'error' => 'Заполните все поля'
                ]);
            }

            if (
                !preg_match('/^[а-яА-ЯёЁa-zA-Z\s-]+$/u', $first_name) ||
                !preg_match('/^[а-яА-ЯёЁa-zA-Z\s-]+$/u', $last_name)
            ) {
                response([
                    'success' => false,
                    'error' => 'Имя и фамилия должны содержать только буквы'
                ]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response([
                    'success' => false,
                    'error' => 'Введите корректный Email'
                ]);
            }

            if (mb_strlen($password) < 6) {
                response([
                    'success' => false,
                    'error' => 'Пароль должен содержать минимум 6 символов'
                ]);
            }

            $password_hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                INSERT INTO users
                (first_name, last_name, `group`, email, password_hash)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $first_name,
                $last_name,
                $group,
                $email,
                $password_hash
            ]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['group'] = $group;
            response(['success' => true]);

        // 3. Авторизация
        case 'login':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response(['success' => false, 'error' => 'Неверный метод запроса'], 405);
            }

            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE email = ?
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                response([
                    'success' => false,
                    'error' => 'Неверный email или пароль'
                ]);
            }

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['group'] = $user['group'];

            response(['success' => true]);


        // 4. Выход
        case 'logout':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response(['success' => false, 'error' => 'Неверный метод запроса'], 405);
            }

            $_SESSION = [];

            session_destroy();

            response(['success' => true]);
                    // 5. Получение категорий
        case 'get_categories':

            $stmt = $pdo->query("
                SELECT *
                FROM categories
                ORDER BY name ASC
            ");

            response([
                'success' => true,
                'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);


        // 6. Каталог заявок
        case 'get_offers':

            $userId = (int) ($_SESSION['user_id'] ?? 0);

            $search = trim($_GET['search'] ?? '');
            $category_id = (int) ($_GET['category_id'] ?? 0);
            $type = $_GET['type'] ?? '';

            $sort_by = $_GET['sort_by'] ?? 'created_at';
            $sort_dir = strtoupper($_GET['sort_dir'] ?? 'DESC');

            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = max(1, min(50, (int) ($_GET['limit'] ?? 6)));

            $offset = ($page - 1) * $limit;

            // Разрешённые поля сортировки
            $allowed_sort = [
                'created_at' => 'o.created_at',
                'title' => 'o.title'
            ];

            $order_column = $allowed_sort[$sort_by] ?? 'o.created_at';

            $order_dir = in_array($sort_dir, ['ASC', 'DESC'], true)
                ? $sort_dir
                : 'DESC';

            // Условия фильтрации
            $where = ["o.status = 'open'"];
            $params = [];

            if (in_array($type, ['need_help', 'can_help'], true)) {
                $where[] = 'o.type = ?';
                $params[] = $type;
            }

            if ($category_id > 0) {
                $where[] = 'o.category_id = ?';
                $params[] = $category_id;
            }

            if ($search !== '') {
                $where[] = '(o.title LIKE ? OR o.description LIKE ?)';

                $like = '%' . $search . '%';

                $params[] = $like;
                $params[] = $like;
            }

            $where_sql = 'WHERE ' . implode(' AND ', $where);

            // Подсчёт заявок
            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM offers o
                $where_sql
            ");

            $countStmt->execute($params);

            $total_items = (int) $countStmt->fetchColumn();

            $total_pages = max(
                1,
                (int) ceil($total_items / $limit)
            );

            // Получение заявок
            $sql = "
                SELECT
                    o.*,
                    c.name AS category_name,
                    u.first_name,
                    u.last_name,
                    u.`group`,

                    (
                        SELECT COUNT(*)
                        FROM requests r
                        WHERE r.offer_id = o.id
                        AND r.user_id = ?
                    ) AS already_replied

                FROM offers o

                JOIN categories c
                    ON o.category_id = c.id

                JOIN users u
                    ON o.user_id = u.id

                $where_sql

                ORDER BY $order_column $order_dir

                LIMIT $limit OFFSET $offset
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute(
                array_merge([$userId], $params)
            );

            response([
                'success' => true,

                'offers' => $stmt->fetchAll(PDO::FETCH_ASSOC),

                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total_items' => $total_items,
                    'total_pages' => $total_pages
                ]
            ]);


        // 7. Создание заявки
        case 'create_offer':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $user_id = (int) $_SESSION['user_id'];

            $category_id = (int) ($_POST['category_id'] ?? 0);

            $type = ($_POST['type'] ?? '') === 'can_help'
                ? 'can_help'
                : 'need_help';

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (
                $category_id <= 0 ||
                $title === '' ||
                $description === ''
            ) {
                response([
                    'success' => false,
                    'error' => 'Выберите предмет и заполните все поля'
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO offers
                (
                    user_id,
                    category_id,
                    type,
                    title,
                    description,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");

            $stmt->execute([
                $user_id,
                $category_id,
                $type,
                $title,
                $description
            ]);

            response(['success' => true]);


        // 8. Отклик на заявку
        case 'create_request':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $offer_id = (int) ($_POST['offer_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');

            if ($offer_id <= 0 || $message === '') {
                response([
                    'success' => false,
                    'error' => 'Введите сообщение для отклика'
                ]);
            }

            // Проверка заявки
            $offerStmt = $pdo->prepare("
                SELECT user_id, status
                FROM offers
                WHERE id = ?
            ");

            $offerStmt->execute([$offer_id]);

            $offer = $offerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$offer) {
                response([
                    'success' => false,
                    'error' => 'Заявка не найдена'
                ]);
            }

            // Запрет отклика на собственную заявку
            if ((int) $offer['user_id'] === $userId) {
                response([
                    'success' => false,
                    'error' => 'Нельзя откликнуться на собственную заявку'
                ]);
            }

            // Проверка статуса
            if ($offer['status'] !== 'open') {
                response([
                    'success' => false,
                    'error' => 'Заявка уже закрыта автором'
                ]);
            }

            // Проверка повторного отклика
            $checkStmt = $pdo->prepare("
                SELECT id
                FROM requests
                WHERE offer_id = ?
                AND user_id = ?
            ");

            $checkStmt->execute([
                $offer_id,
                $userId
            ]);

            if ($checkStmt->fetch()) {
                response([
                    'success' => false,
                    'error' => 'Вы уже откликнулись на эту заявку'
                ]);
            }

            // Создание отклика
            $stmt = $pdo->prepare("
                INSERT INTO requests
                (offer_id, user_id, message)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $offer_id,
                $UserId = $userId,
                $message
            ]);

            response(['success' => true]);
                    // 9. Получение данных личного кабинета
        case 'get_profile_data':

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            // Информация о пользователе
            $stmtUser = $pdo->prepare("
                SELECT
                    id,
                    first_name,
                    last_name,
                    `group`,
                    email
                FROM users
                WHERE id = ?
            ");

            $stmtUser->execute([$userId]);

            // Мои заявки
            $stmtOffers = $pdo->prepare("
                SELECT
                    o.*,
                    c.name AS category_name
                FROM offers o
                JOIN categories c ON o.category_id = c.id
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
            ");

            $stmtOffers->execute([$userId]);

            // Входящие отклики
            $stmtIncoming = $pdo->prepare("
                SELECT
                    r.*,
                    o.title AS offer_title,
                    u.first_name,
                    u.last_name,
                    u.`group`
                FROM requests r
                JOIN offers o ON r.offer_id = o.id
                JOIN users u ON r.user_id = u.id
                WHERE o.user_id = ?
                ORDER BY r.created_at DESC
            ");

            $stmtIncoming->execute([$userId]);

            // Исходящие отклики
            $stmtOutgoing = $pdo->prepare("
                SELECT
                    r.*,
                    o.title AS offer_title,
                    o.status AS offer_status,
                    c.name AS category_name,
                    u.first_name,
                    u.last_name,
                    u.`group`
                FROM requests r
                JOIN offers o ON r.offer_id = o.id
                JOIN categories c ON o.category_id = c.id
                JOIN users u ON o.user_id = u.id
                WHERE r.user_id = ?
                ORDER BY r.created_at DESC
            ");

            $stmtOutgoing->execute([$userId]);

            response([
                'success' => true,

                'user' => $stmtUser->fetch(PDO::FETCH_ASSOC),

                'my_offers' => $stmtOffers->fetchAll(PDO::FETCH_ASSOC),

                'incoming_requests' => $stmtIncoming->fetchAll(PDO::FETCH_ASSOC),

                'outgoing_requests' => $stmtOutgoing->fetchAll(PDO::FETCH_ASSOC)
            ]);


        // 10. Обновление профиля
        case 'update_profile':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $group = trim($_POST['group'] ?? '');
            $email = trim($_POST['email'] ?? '');

            // Проверка заполнения
            if (
                $first_name === '' ||
                $last_name === '' ||
                $group === '' ||
                $email === ''
            ) {
                response([
                    'success' => false,
                    'error' => 'Заполните все поля'
                ]);
            }

            // Проверка имени и фамилии
            if (
                !preg_match('/^[а-яА-ЯёЁa-zA-Z\s-]+$/u', $first_name) ||
                !preg_match('/^[а-яА-ЯёЁa-zA-Z\s-]+$/u', $last_name)
            ) {
                response([
                    'success' => false,
                    'error' => 'Имя и фамилия должны содержать только буквы'
                ]);
            }

            // Проверка группы
            if (!preg_match('/^[а-яА-ЯёЁa-zA-Z0-9\s.-]+$/u', $group)) {
                response([
                    'success' => false,
                    'error' => 'Название группы содержит недопустимые символы'
                ]);
            }

            // Проверка Email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response([
                    'success' => false,
                    'error' => 'Некорректный формат Email'
                ]);
            }

            // Обновление данных
            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    first_name = ?,
                    last_name = ?,
                    `group` = ?,
                    email = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $first_name,
                $last_name,
                $group,
                $email,
                $userId
            ]);

            // Обновление сессии
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['group'] = $group;

            response(['success' => true]);


        // 11. Смена пароля
        case 'change_password':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $old_password = $_POST['old_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Проверка заполнения
            if (
                $old_password === '' ||
                $new_password === '' ||
                $confirm_password === ''
            ) {
                response([
                    'success' => false,
                    'error' => 'Заполните все поля'
                ]);
            }

            // Проверка длины пароля
            if (mb_strlen($new_password) < 6) {
                response([
                    'success' => false,
                    'error' => 'Новый пароль должен содержать минимум 6 символов'
                ]);
            }

            // Проверка совпадения паролей
            if ($new_password !== $confirm_password) {
                response([
                    'success' => false,
                    'error' => 'Новые пароли не совпадают'
                ]);
            }

            // Получение текущего пароля
            $stmt = $pdo->prepare("
                SELECT password_hash
                FROM users
                WHERE id = ?
            ");

            $stmt->execute([$userId]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Проверка старого пароля
            if (
                !$user ||
                !password_verify($old_password, $user['password_hash'])
            ) {
                response([
                    'success' => false,
                    'error' => 'Неверный текущий пароль'
                ]);
            }

            // Сохранение нового пароля
            $new_hash = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $updateStmt = $pdo->prepare("
                UPDATE users
                SET password_hash = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $new_hash,
                $userId
            ]);

            response(['success' => true]);
                    // 12. Редактирование своей заявки
        case 'update_offer':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $offer_id = (int) ($_POST['offer_id'] ?? 0);
            $category_id = (int) ($_POST['category_id'] ?? 0);

            $type = ($_POST['type'] ?? '') === 'can_help'
                ? 'can_help'
                : 'need_help';

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            $status = ($_POST['status'] ?? 'open') === 'closed'
                ? 'closed'
                : 'open';

            // Проверка данных
            if (
                $offer_id <= 0 ||
                $category_id <= 0 ||
                $title === '' ||
                $description === ''
            ) {
                response([
                    'success' => false,
                    'error' => 'Заполните все поля заявки'
                ]);
            }

            // Обновление только собственной заявки
            $stmt = $pdo->prepare("
                UPDATE offers
                SET
                    category_id = ?,
                    type = ?,
                    title = ?,
                    description = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([
                $category_id,
                $type,
                $title,
                $description,
                $status,
                $offer_id,
                $UserId = $userId
            ]);

            // Проверка существования заявки
            if ($stmt->rowCount() === 0) {

                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM offers
                    WHERE id = ? AND user_id = ?
                ");

                $checkStmt->execute([
                    $offer_id,
                    $userId
                ]);

                if (!$checkStmt->fetch()) {
                    response([
                        'success' => false,
                        'error' => 'Заявка не найдена или у вас нет прав на её изменение'
                    ]);
                }
            }

            response([
                'success' => true
            ]);


        // 13. Удаление своей заявки
        case 'delete_offer':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                response([
                    'success' => false,
                    'error' => 'Неверный метод запроса'
                ], 405);
            }

            if (!isset($_SESSION['user_id'])) {
                response([
                    'success' => false,
                    'error' => 'Не авторизован'
                ], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $offer_id = (int) ($_POST['offer_id'] ?? 0);

            if ($offer_id <= 0) {
                response([
                    'success' => false,
                    'error' => 'Некорректный ID заявки'
                ]);
            }

            // Удаление только собственной заявки
            $stmt = $pdo->prepare("
                DELETE FROM offers
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([
                $offer_id,
                $userId
            ]);

            if ($stmt->rowCount() === 0) {
                response([
                    'success' => false,
                    'error' => 'Заявка не найдена или у вас нет прав на её удаление'
                ]);
            }

            response([
                'success' => true
            ]);


        // Неизвестное действие
        default:

            response([
                'success' => false,
                'error' => 'Неизвестное действие'
            ], 404);

    }

} catch (PDOException $e) {

    // Запись ошибки в журнал сервера
    error_log($e->getMessage());

    $message = 'Ошибка при работе с базой данных';

    if ($e->getCode() === '23000') {
        $message = 'Email уже зарегистрирован или занят другим пользователем';
    }

    response([
        'success' => false,
        'error' => $message
    ], 500);

} catch (Throwable $e) {

    error_log($e->getMessage());

    response([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], 500);

}

?>