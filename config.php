<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Генерация CSRF-токена для текущей сессии
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db_file = __DIR__ . '/database.db';
$is_new_db = !file_exists($db_file) || filesize($db_file) === 0;

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($is_new_db) {
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                `group` VARCHAR(20) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) UNIQUE NOT NULL
            );

            CREATE TABLE offers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                category_id INT NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'need_help',
                title VARCHAR(150) NOT NULL,
                description TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            );

            CREATE TABLE requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offer_id INT NOT NULL,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TRIGGER update_offers_timestamp 
            AFTER UPDATE ON offers
            FOR EACH ROW
            BEGIN
                UPDATE offers SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
            END;

            INSERT INTO users (id, first_name, last_name, `group`, email, password_hash, created_at) VALUES
            (1, 'Роман', 'Аксенов', 'ИСП-341', 'rukotiko54@gmail.com', '$2y$10$Nxa.fQ5lLdc1HxovIKRHqucQk/.3IZ5Y/CHiNww9KBGK7aSHAyf/m', '2026-11-11 01:57:18'),
            (2, 'Павел', 'Кунгуров', 'ИСП-341', 'pavelkungurov08@mail.ru', '$2y$10$oRuNr2Uti5r92V/wIr6xGOGoc7497W3nOExkbWjjSIFKRuhSJJ3BK', '2026-11-11 02:48:14');

            INSERT INTO categories (id, name) VALUES
            (1, 'История'), (2, 'Физика'), (3, 'Программирование'), (4, 'Математика'),
            (5, 'Иностранный язык'), (6, 'Поддержка и тестирование пр.модулей'),
            (7, 'Физ-ра'), (8, 'Разработка пр.модулей');

            INSERT INTO offers (id, user_id, category_id, type, title, description, status, created_at, updated_at) VALUES
            (1, 1, 8, 'need_help', 'Создание приложение на python', 'Помогите написать desktop приложение на PyQT6', 'open', '2026-11-11 02:59:37', '2026-11-11 03:34:15'),
            (2, 2, 4, 'can_help', 'Интегралы', 'Помогу разобраться в интегралах и решении задач', 'open', '2026-11-11 03:17:22', '2026-11-11 04:21:28');

            INSERT INTO requests (id, offer_id, user_id, message, status, created_at) VALUES
            (1, 1, 2, '@pasha08 TG', 'pending', '2026-11-11 03:00:23');
        ");
    }

    // 6. Создание индексов для ускорения фильтрации, поиска и сортировки
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_offers_status_created ON offers(status, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_offers_category ON offers(category_id);
        CREATE INDEX IF NOT EXISTS idx_offers_type ON offers(type);
        CREATE INDEX IF NOT EXISTS idx_offers_user ON offers(user_id);
        CREATE INDEX IF NOT EXISTS idx_requests_offer_user ON requests(offer_id, user_id);
    ");

} catch (\PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
    exit;
}
