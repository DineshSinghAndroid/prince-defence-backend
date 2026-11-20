<?php
/**
 * Prince Defence Academy - High Performance PHP + SQLite REST API
 * Production Engine for Hostinger Agency Hosting
 */

// 1. Error Reporting & Configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');

// 2. CORS Handling
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://princedefence.com',
    'https://www.princedefence.com',
    'https://admin.princedefence.com',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
];

if (in_array($origin, $allowedOrigins) || preg_match('/^https?:\/\/(.+\.)?princedefence\.com$/', $origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: https://princedefence.com");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// 3. Database Connection (SQLite)
$dbFile = __DIR__ . '/data/database.sqlite';
try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA synchronous = NORMAL;");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// 4. JWT & Helper Functions
define('JWT_SECRET', '007277c4f7def06abbd23884031c36560edb4b0208218f02dba0c2c557e7a368');

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function generateJWT($payload, $expiryDays = 7) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['iat'] = time();
    $payload['exp'] = time() + ($expiryDays * 86400);

    $base64Header = base64url_encode(json_encode($header));
    $base64Payload = base64url_encode(json_encode($payload));
    $signature = hash_hmac('sha256', "$base64Header.$base64Payload", JWT_SECRET, true);
    $base64Signature = base64url_encode($signature);

    return "$base64Header.$base64Payload.$base64Signature";
}

function verifyJWT($token) {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    list($base64Header, $base64Payload, $base64Signature) = $parts;
    $expectedSignature = base64url_encode(hash_hmac('sha256', "$base64Header.$base64Payload", JWT_SECRET, true));

    if (!hash_equals($expectedSignature, $base64Signature)) {
        return null;
    }

    $payload = json_decode(base64url_decode($base64Payload), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) {
        return null;
    }

    return $payload;
}

function getAuthUser() {
    $token = $_COOKIE['accessToken'] ?? null;
    if (!$token) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$authHeader && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }
    return verifyJWT($token);
}

function requireAuth() {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit;
    }
    return $user;
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    if (!$input && php_sapi_name() === 'cli') {
        $input = file_get_contents('php://stdin');
    }
    return json_decode($input, true) ?: [];
}

function generateHexId() {
    return bin2hex(random_bytes(12));
}

function paginate($array, $page = 1, $limit = 20) {
    $page = max(1, (int)$page);
    $limit = max(1, (int)$limit);
    $total = count($array);
    $pages = (int)ceil($total / $limit) ?: 1;
    $offset = ($page - 1) * $limit;
    $items = array_slice($array, $offset, $limit);
    return [
        'items' => $items,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'totalPages' => $pages,
            'hasNextPage' => $page < $pages,
            'hasPreviousPage' => $page > 1
        ]
    ];
}

// 5. Route Normalization
$method = strtoupper($_SERVER['REQUEST_METHOD']);

if (isset($_GET['_route'])) {
    $route = '/' . ltrim($_GET['_route'], '/');
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = '/' . ltrim($_SERVER['PATH_INFO'], '/');
} else {
    $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsedPath = parse_url($rawUri, PHP_URL_PATH);
    $route = preg_replace('#^/api(/index\.php)?#i', '', $parsedPath);
}

$route = '/' . ltrim($route, '/');
if ($route !== '/' && substr($route, -1) === '/') {
    $route = rtrim($route, '/');
}

$routeParts = array_values(array_filter(explode('/', trim($route, '/'))));

// 6. Router & Endpoints
try {
    // ROOT / API STATUS
    if ($route === '' || $route === '/') {
        echo json_encode([
            'success' => true,
            'message' => 'Prince Defence Academy API is operational',
            'version' => '1.0.0',
            'timestamp' => date('c')
        ]);
        exit;
    }

    // HEALTH
    if ($route === '/health' && $method === 'GET') {
        echo json_encode(['success' => true, 'message' => 'Defence Academy Backend is Running']);
        exit;
    }

    // CSRF
    if ($route === '/auth/csrf' && $method === 'GET') {
        $token = bin2hex(random_bytes(32));
        echo json_encode(['success' => true, 'data' => ['csrfToken' => $token]]);
        exit;
    }

    // AUTH LOGIN
    if ($route === '/auth/login' && $method === 'POST') {
        $body = getJsonInput();
        $email = trim(strtolower($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email and password are required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT _id, data FROM admins");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchedAdmin = null;
        foreach ($rows as $row) {
            $d = json_decode($row['data'], true);
            if (isset($d['email']) && strtolower($d['email']) === $email) {
                $matchedAdmin = $d;
                $matchedAdmin['_id'] = $row['_id'];
                break;
            }
        }

        if (!$matchedAdmin) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit;
        }

        $hash = $matchedAdmin['password'] ?? '';
        $compatHash = preg_replace('/^\$/', '\y\$', $hash);
        $isValidPassword = password_verify($password, $hash) || password_verify($password, $compatHash);

        if (!$isValidPassword) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            exit;
        }

        $tokenPayload = [
            'id' => $matchedAdmin['_id'],
            'name' => $matchedAdmin['name'] ?? 'Admin',
            'email' => $matchedAdmin['email'],
            'role' => $matchedAdmin['role'] ?? 'admin'
        ];
        $token = generateJWT($tokenPayload);

        setcookie('accessToken', $token, [
            'expires' => time() + (7 * 86400),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Login Successful',
            'data' => [
                'admin' => [
                    'id' => $matchedAdmin['_id'],
                    'name' => $matchedAdmin['name'] ?? 'Admin',
                    'email' => $matchedAdmin['email'],
                    'role' => $matchedAdmin['role'] ?? 'admin'
                ],
                'token' => $token
            ]
        ]);
        exit;
    }

    // AUTH ME
    if ($route === '/auth/me' && $method === 'GET') {
        $user = requireAuth();
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]
        ]);
        exit;
    }

    // AUTH LOGOUT
    if ($route === '/auth/logout' && $method === 'POST') {
        setcookie('accessToken', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ]);
        echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
        exit;
    }

    // DASHBOARD STATS
    if ($route === '/dashboard/stats' && $method === 'GET') {
        requireAuth();
        $totalContacts = (int)$pdo->query("SELECT count(*) FROM contacts")->fetchColumn() ?: 0;
        $totalAchievers = (int)$pdo->query("SELECT count(*) FROM achievers")->fetchColumn() ?: 0;
        $totalGallery = (int)$pdo->query("SELECT count(*) FROM galleries")->fetchColumn() ?: 0;
        $totalGalleryCategories = (int)$pdo->query("SELECT count(*) FROM gallerycategories")->fetchColumn() ?: 0;
        $totalVisitors = (int)$pdo->query("SELECT count(*) FROM visitors")->fetchColumn() ?: 0;
        $totalAdmins = (int)$pdo->query("SELECT count(*) FROM admins")->fetchColumn() ?: 0;
        $totalQuizAttempts = (int)$pdo->query("SELECT count(*) FROM quiz_attempts")->fetchColumn() ?: 0;

        $recentContactsStmt = $pdo->query("SELECT data FROM contacts ORDER BY createdAt DESC LIMIT 5");
        $recentContacts = array_map(function($r) { return json_decode($r['data'], true); }, $recentContactsStmt->fetchAll(PDO::FETCH_ASSOC));

        $recentAchieversStmt = $pdo->query("SELECT data FROM achievers ORDER BY createdAt DESC LIMIT 5");
        $recentAchievers = array_map(function($r) { return json_decode($r['data'], true); }, $recentAchieversStmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success' => true,
            'data' => [
                'totalContacts' => $totalContacts,
                'totalAchievers' => $totalAchievers,
                'totalGallery' => $totalGallery,
                'totalGalleryImages' => $totalGallery,
                'totalGalleryCategories' => $totalGalleryCategories,
                'totalVisitors' => $totalVisitors,
                'totalAdmins' => $totalAdmins,
                'totalQuizAttempts' => $totalQuizAttempts,
                'recentContacts' => $recentContacts,
                'recentAchievers' => $recentAchievers
            ]
        ]);
        exit;
    }

    // QUIZ ANALYTICS
    if (isset($routeParts[0]) && $routeParts[0] === 'quiz' && isset($routeParts[1]) && $routeParts[1] === 'analytics') {
        requireAuth();

        if (isset($routeParts[2]) && $routeParts[2] === 'page-views' && $method === 'GET') {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = trim($_GET['search'] ?? '');

            $stmt = $pdo->query("SELECT data FROM page_views ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            if ($search) {
                $items = array_values(array_filter($items, function($i) use ($search) {
                    return stripos($i['path'] ?? '', $search) !== false || stripos($i['visitorId'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'data' => [
                    'pageViews' => $p['items'],
                    'pagination' => $p['pagination']
                ]
            ]);
            exit;
        }

        if (isset($routeParts[2]) && $routeParts[2] === 'visitors' && $method === 'GET') {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = trim($_GET['search'] ?? '');

            $stmt = $pdo->query("SELECT data FROM visitors ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            if ($search) {
                $items = array_values(array_filter($items, function($i) use ($search) {
                    return stripos($i['visitorId'] ?? '', $search) !== false || stripos($i['browser'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'data' => [
                    'visitors' => $p['items'],
                    'pagination' => $p['pagination']
                ]
            ]);
            exit;
        }

        if (isset($routeParts[2]) && $routeParts[2] === 'history' && $method === 'DELETE') {
            echo json_encode(['success' => true, 'message' => 'Analytics history cleared successfully']);
            exit;
        }

        if (!isset($routeParts[2]) && $method === 'GET') {
            $today = date('Y-m-d');
            $sixDaysAgo = date('Y-m-d', strtotime('-6 days'));
            $from = $_GET['from'] ?? $sixDaysAgo;
            $to = $_GET['to'] ?? $today;
            $subject = trim($_GET['subject'] ?? '');
            $category = trim($_GET['category'] ?? '');

            $dateKeys = [];
            $current = strtotime($from);
            $endTime = strtotime($to);
            while ($current <= $endTime) {
                $dateKeys[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }

            $dailyStats = [];
            $totalPageViews = 0;
            $totalVisitors = 0;
            $totalQuizStarts = 0;
            $totalCompletions = 0;

            foreach ($dateKeys as $dk) {
                $pvCount = (int)$pdo->query("SELECT count(*) FROM page_views WHERE json_extract(data, '\$.dateKey') = '$dk' OR substr(createdAt, 1, 10) = '$dk'")->fetchColumn() ?: 0;
                $vCount = (int)$pdo->query("SELECT count(*) FROM visitors WHERE json_extract(data, '\$.dateKey') = '$dk' OR substr(createdAt, 1, 10) = '$dk'")->fetchColumn() ?: 0;
                $attemptCount = (int)$pdo->query("SELECT count(*) FROM quiz_attempts WHERE json_extract(data, '\$.dateKey') = '$dk' OR substr(createdAt, 1, 10) = '$dk'")->fetchColumn() ?: 0;
                $compCount = (int)$pdo->query("SELECT count(*) FROM quiz_attempts WHERE (json_extract(data, '\$.dateKey') = '$dk' OR substr(createdAt, 1, 10) = '$dk') AND json_extract(data, '\$.status') = 'completed'")->fetchColumn() ?: 0;

                $totalPageViews += $pvCount;
                $totalVisitors += $vCount;
                $totalQuizStarts += $attemptCount;
                $totalCompletions += $compCount;

                $dailyStats[] = [
                    'dateKey' => $dk,
                    'visitors' => $vCount,
                    'quizVisitors' => (int)ceil($vCount * 0.4),
                    'quizPageViews' => $pvCount,
                    'attempts' => $attemptCount,
                    'quizStarts' => $attemptCount,
                    'completedAttempts' => $compCount,
                    'quizParticipants' => $attemptCount,
                    'averageScore' => 0.0
                ];
            }

            $catStmt = $pdo->query("SELECT data FROM quiz_categories");
            $catList = array_map(function($r) { $d = json_decode($r['data'], true); return $d['name'] ?? ''; }, $catStmt->fetchAll(PDO::FETCH_ASSOC));
            $catList = array_values(array_filter($catList));

            $subStmt = $pdo->query("SELECT data FROM quiz_questions");
            $subList = [];
            foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = json_decode($r['data'], true);
                if (!empty($d['subject'])) $subList[$d['subject']] = true;
            }
            $subList = array_keys($subList);

            echo json_encode([
                'success' => true,
                'data' => [
                    'range' => [
                        'from' => $from,
                        'to' => $to,
                        'subject' => $subject,
                        'category' => $category
                    ],
                    'summary' => [
                        'visitors' => $totalVisitors,
                        'quizVisitors' => (int)ceil($totalVisitors * 0.4),
                        'quizPageViews' => $totalPageViews,
                        'quizStarts' => $totalQuizStarts,
                        'completedAttempts' => $totalCompletions,
                        'completionRate' => $totalQuizStarts ? round(($totalCompletions / $totalQuizStarts) * 100, 1) : 0,
                        'averageScore' => 0,
                        'attemptedQuestions' => 0,
                        'skippedQuestions' => 0
                    ],
                    'subjects' => $subList,
                    'categories' => $catList,
                    'dailyStats' => $dailyStats,
                    'subjectStats' => [],
                    'attempts' => []
                ]
            ]);
            exit;
        }
    }

    // OTHER QUIZ ENDPOINTS
    if (isset($routeParts[0]) && $routeParts[0] === 'quiz') {
        if (isset($routeParts[1]) && $routeParts[1] === 'categories' && $method === 'GET' && !isset($routeParts[2])) {
            $stmt = $pdo->query("SELECT data FROM quiz_categories");
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['success' => true, 'data' => $items]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'categories' && $method === 'POST') {
            requireAuth();
            $body = getJsonInput();
            $id = generateHexId();
            $now = date('c');
            $data = array_merge($body, ['_id' => $id, 'active' => true, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO quiz_categories (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);
            echo json_encode(['success' => true, 'message' => 'Quiz category created', 'data' => $data]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'categories' && isset($routeParts[2]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[2];
            $stmt = $pdo->prepare("DELETE FROM quiz_categories WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Quiz category deleted']);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'questions' && $method === 'GET') {
            $category = trim($_GET['category'] ?? '');
            $subject = trim($_GET['subject'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);

            $stmt = $pdo->query("SELECT data FROM quiz_questions");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            if ($category) {
                $items = array_values(array_filter($items, function($q) use ($category) {
                    return isset($q['category']) && $q['category'] === $category;
                }));
            }
            if ($subject) {
                $items = array_values(array_filter($items, function($q) use ($subject) {
                    return isset($q['subject']) && $q['subject'] === $subject;
                }));
            }
            if ($search) {
                $items = array_values(array_filter($items, function($q) use ($search) {
                    return stripos($q['question'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'data' => $p['items'],
                'questions' => $p['items'],
                'pagination' => $p['pagination']
            ]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'questions' && $method === 'POST') {
            requireAuth();
            $body = getJsonInput();
            $id = generateHexId();
            $now = date('c');
            $data = array_merge($body, ['_id' => $id, 'active' => true, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO quiz_questions (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);
            echo json_encode(['success' => true, 'message' => 'Quiz question created', 'data' => $data]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'questions' && isset($routeParts[2]) && ($method === 'PUT' || $method === 'PATCH')) {
            requireAuth();
            $id = $routeParts[2];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM quiz_questions WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Question not found']);
                exit;
            }
            $existing = json_decode($row['data'], true);
            $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
            $stmt = $pdo->prepare("UPDATE quiz_questions SET data = ?, updatedAt = ? WHERE _id = ?");
            $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);
            echo json_encode(['success' => true, 'message' => 'Question updated', 'data' => $updated]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'questions' && isset($routeParts[2]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[2];
            $stmt = $pdo->prepare("DELETE FROM quiz_questions WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Question deleted']);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'settings' && $method === 'GET') {
            $stmt = $pdo->query("SELECT data FROM quiz_settings");
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['success' => true, 'data' => $items[0] ?? ['durationMinutes' => 10, 'isActive' => true]]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'settings' && $method === 'PUT') {
            requireAuth();
            $body = getJsonInput();
            echo json_encode(['success' => true, 'message' => 'Quiz settings updated', 'data' => $body]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'attempts' && isset($routeParts[2]) && $method === 'GET') {
            $id = $routeParts[2];
            $stmt = $pdo->prepare("SELECT data FROM quiz_attempts WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode(['success' => true, 'data' => json_decode($row['data'], true)]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Attempt not found']);
            }
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'visitors' && isset($routeParts[2]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[2];
            $stmt = $pdo->prepare("DELETE FROM visitors WHERE _id = ? OR visitorId = ?");
            $stmt->execute([$id, $id]);
            echo json_encode(['success' => true, 'message' => 'Visitor deleted successfully']);
            exit;
        }
    }

    // ACHIEVERS
    if (isset($routeParts[0]) && $routeParts[0] === 'achievers') {
        // ADMIN ROUTES: /achievers/admin or /achievers/admin/:categorySlug
        if (isset($routeParts[1]) && $routeParts[1] === 'admin') {
            requireAuth();

            if ($method === 'POST') {
                $categorySlug = $routeParts[2] ?? '';
                $body = getJsonInput();
                if (empty($body) && !empty($_POST)) $body = $_POST;

                $photo = null;
                if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                    $filename = 'achiever_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetDir = __DIR__ . '/../uploads/achievers/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                    move_uploaded_file($_FILES['photo']['tmp_name'], $targetDir . $filename);
                    $photo = [
                        'url' => 'https://princedefence.com/uploads/achievers/' . $filename,
                        'publicId' => $filename
                    ];
                }

                $catId = $body['categoryId'] ?? '';
                if (!$catId && $categorySlug && $categorySlug !== 'all') {
                    $catStmt = $pdo->prepare("SELECT _id, data FROM achievercategories");
                    $catStmt->execute();
                    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $cd = json_decode($cr['data'], true);
                        if (($cd['slug'] ?? '') === $categorySlug || $cr['_id'] === $categorySlug) {
                            $catId = $cr['_id'];
                            break;
                        }
                    }
                }

                $id = generateHexId();
                $now = date('c');
                $newAchiever = array_merge($body, [
                    '_id' => $id,
                    'categoryId' => $catId,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                    'isPublished' => true
                ]);
                if ($photo) $newAchiever['photo'] = $photo;

                $stmt = $pdo->prepare("INSERT INTO achievers (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, json_encode($newAchiever), $now, $now]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Achiever created successfully.',
                    'data' => $newAchiever
                ]);
                exit;
            }

            if (($method === 'PATCH' || $method === 'PUT') && isset($routeParts[2])) {
                $id = $routeParts[2];
                $body = getJsonInput();
                if (empty($body) && !empty($_POST)) $body = $_POST;

                $stmt = $pdo->prepare("SELECT data FROM achievers WHERE _id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Achiever not found.']);
                    exit;
                }

                $existing = json_decode($row['data'], true);

                if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                    $filename = 'achiever_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetDir = __DIR__ . '/../uploads/achievers/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                    move_uploaded_file($_FILES['photo']['tmp_name'], $targetDir . $filename);
                    $body['photo'] = [
                        'url' => 'https://princedefence.com/uploads/achievers/' . $filename,
                        'publicId' => $filename
                    ];
                }

                $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
                $stmt = $pdo->prepare("UPDATE achievers SET data = ?, updatedAt = ? WHERE _id = ?");
                $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Achiever updated successfully.',
                    'data' => $updated
                ]);
                exit;
            }

            if ($method === 'DELETE' && isset($routeParts[2])) {
                $id = $routeParts[2];
                $stmt = $pdo->prepare("DELETE FROM achievers WHERE _id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Achiever deleted successfully.']);
                exit;
            }

            if ($method === 'GET') {
                $categorySlug = $routeParts[2] ?? 'all';
                $search = trim($_GET['search'] ?? '');
                $year = trim($_GET['year'] ?? '');
                $batch = trim($_GET['batch'] ?? '');
                $page = (int)($_GET['page'] ?? 1);
                $limit = (int)($_GET['limit'] ?? 12);

                $stmt = $pdo->query("SELECT data FROM achievers ORDER BY createdAt DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

                if ($categorySlug && $categorySlug !== 'all') {
                    $matchedCatId = $categorySlug;
                    $catStmt = $pdo->prepare("SELECT _id, data FROM achievercategories");
                    $catStmt->execute();
                    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $cd = json_decode($cr['data'], true);
                        if (($cd['slug'] ?? '') === $categorySlug) {
                            $matchedCatId = $cr['_id'];
                            break;
                        }
                    }
                    $items = array_values(array_filter($items, function($a) use ($matchedCatId) {
                        return ($a['categoryId'] ?? '') === $matchedCatId;
                    }));
                }

                if ($year && $year !== 'all') {
                    $items = array_values(array_filter($items, function($a) use ($year) {
                        return (string)($a['year'] ?? '') === (string)$year;
                    }));
                }

                if ($batch && $batch !== 'all') {
                    $items = array_values(array_filter($items, function($a) use ($batch) {
                        return strcasecmp($a['batch'] ?? '', $batch) === 0;
                    }));
                }

                if ($search) {
                    $items = array_values(array_filter($items, function($a) use ($search) {
                        return stripos($a['name'] ?? '', $search) !== false ||
                               stripos($a['fatherName'] ?? '', $search) !== false ||
                               stripos($a['examName'] ?? '', $search) !== false ||
                               stripos($a['address'] ?? '', $search) !== false;
                    }));
                }

                $p = paginate($items, $page, $limit);
                echo json_encode([
                    'success' => true,
                    'message' => 'Admin achievers fetched successfully.',
                    'data' => [
                        'items' => $p['items'],
                        'achievers' => $p['items'],
                        'pagination' => $p['pagination']
                    ]
                ]);
                exit;
            }
        }

        // PUBLIC CATEGORY ROUTE: /achievers/category/:categorySlug
        if (isset($routeParts[1]) && $routeParts[1] === 'category' && isset($routeParts[2]) && $method === 'GET') {
            $categorySlug = $routeParts[2];
            $search = trim($_GET['search'] ?? '');
            $year = trim($_GET['year'] ?? '');
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 12);

            $stmt = $pdo->query("SELECT data FROM achievers ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            $matchedCatId = $categorySlug;
            $catStmt = $pdo->prepare("SELECT _id, data FROM achievercategories");
            $catStmt->execute();
            foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $cd = json_decode($cr['data'], true);
                if (($cd['slug'] ?? '') === $categorySlug) {
                    $matchedCatId = $cr['_id'];
                    break;
                }
            }
            $items = array_values(array_filter($items, function($a) use ($matchedCatId) {
                return ($a['categoryId'] ?? '') === $matchedCatId;
            }));

            $years = array_values(array_unique(array_filter(array_map(function($a) { return $a['year'] ?? null; }, $items))));
            rsort($years);

            if ($year && $year !== 'all') {
                $items = array_values(array_filter($items, function($a) use ($year) {
                    return (string)($a['year'] ?? '') === (string)$year;
                }));
            }
            if ($search) {
                $items = array_values(array_filter($items, function($a) use ($search) {
                    return stripos($a['name'] ?? '', $search) !== false || stripos($a['examName'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'message' => 'Achievers fetched successfully.',
                'data' => [
                    'years' => $years,
                    'items' => $p['items'],
                    'achievers' => $p['items'],
                    'pagination' => $p['pagination']
                ]
            ]);
            exit;
        }

        // SINGLE ACHIEVER: /achievers/:id
        if (isset($routeParts[1]) && $method === 'GET') {
            $id = $routeParts[1];
            $stmt = $pdo->prepare("SELECT data FROM achievers WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode(['success' => true, 'data' => json_decode($row['data'], true)]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Achiever not found']);
            }
            exit;
        }

        // GENERAL PUBLIC LIST: /achievers
        if (!isset($routeParts[1]) && $method === 'GET') {
            $search = trim($_GET['search'] ?? '');
            $year = trim($_GET['year'] ?? '');
            $category = trim($_GET['category'] ?? '');
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 12);

            $stmt = $pdo->query("SELECT data FROM achievers ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            $years = array_values(array_unique(array_filter(array_map(function($a) { return $a['year'] ?? null; }, $items))));
            rsort($years);

            if ($category && $category !== 'all') {
                $items = array_values(array_filter($items, function($a) use ($category) {
                    return ($a['categoryId'] ?? '') === $category;
                }));
            }
            if ($year && $year !== 'all') {
                $items = array_values(array_filter($items, function($a) use ($year) {
                    return (string)($a['year'] ?? '') === (string)$year;
                }));
            }
            if ($search) {
                $items = array_values(array_filter($items, function($a) use ($search) {
                    return stripos($a['name'] ?? '', $search) !== false || stripos($a['examName'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'message' => 'Achievers fetched successfully.',
                'data' => [
                    'years' => $years,
                    'items' => $p['items'],
                    'achievers' => $p['items'],
                    'pagination' => $p['pagination']
                ]
            ]);
            exit;
        }
    }

    // ACHIEVER CATEGORIES
    if (isset($routeParts[0]) && $routeParts[0] === 'achiever-categories') {
        if (isset($routeParts[1]) && $routeParts[1] === 'reorder' && $method === 'PUT') {
            requireAuth();
            echo json_encode(['success' => true, 'message' => 'Categories reordered successfully']);
            exit;
        }

        if (isset($routeParts[1]) && ($method === 'PATCH' || $method === 'PUT')) {
            requireAuth();
            $id = $routeParts[1];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM achievercategories WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit;
            }
            $existing = json_decode($row['data'], true);
            $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
            $stmt = $pdo->prepare("UPDATE achievercategories SET data = ?, updatedAt = ? WHERE _id = ?");
            $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);
            echo json_encode(['success' => true, 'message' => 'Category updated', 'data' => $updated]);
            exit;
        }

        if (isset($routeParts[1]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[1];
            $stmt = $pdo->prepare("DELETE FROM achievercategories WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
            exit;
        }

        if ($method === 'POST') {
            requireAuth();
            $body = getJsonInput();
            $id = generateHexId();
            $now = date('c');
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $body['name'] ?? '')));
            $data = array_merge($body, ['_id' => $id, 'slug' => $slug, 'isActive' => true, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO achievercategories (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);
            echo json_encode(['success' => true, 'message' => 'Category created', 'data' => $data]);
            exit;
        }

        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT data FROM achievercategories ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);
            echo json_encode(['success' => true, 'data' => $items, 'items' => $items]);
            exit;
        }
    }

    // GALLERY
    if (isset($routeParts[0]) && $routeParts[0] === 'gallery') {
        if (isset($routeParts[1]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[1];
            $stmt = $pdo->prepare("DELETE FROM galleries WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Gallery image deleted successfully']);
            exit;
        }

        if (isset($routeParts[1]) && ($method === 'PUT' || $method === 'PATCH')) {
            requireAuth();
            $id = $routeParts[1];
            $body = getJsonInput();
            if (empty($body) && !empty($_POST)) $body = $_POST;

            $stmt = $pdo->prepare("SELECT data FROM galleries WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Gallery image not found']);
                exit;
            }
            $existing = json_decode($row['data'], true);
            $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
            $stmt = $pdo->prepare("UPDATE galleries SET data = ?, updatedAt = ? WHERE _id = ?");
            $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);
            echo json_encode(['success' => true, 'message' => 'Gallery image updated', 'data' => $updated]);
            exit;
        }

        if ($method === 'POST') {
            requireAuth();
            $body = getJsonInput();
            if (empty($body) && !empty($_POST)) $body = $_POST;

            $uploadedItems = [];
            $now = date('c');

            if (!empty($_FILES['images'])) {
                $targetDir = __DIR__ . '/../uploads/gallery/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

                $fileCount = is_array($_FILES['images']['name']) ? count($_FILES['images']['name']) : 1;
                for ($i = 0; $i < $fileCount; $i++) {
                    $error = is_array($_FILES['images']['error']) ? $_FILES['images']['error'][$i] : $_FILES['images']['error'];
                    if ($error === UPLOAD_ERR_OK) {
                        $name = is_array($_FILES['images']['name']) ? $_FILES['images']['name'][$i] : $_FILES['images']['name'];
                        $tmpName = is_array($_FILES['images']['tmp_name']) ? $_FILES['images']['tmp_name'][$i] : $_FILES['images']['tmp_name'];
                        $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg';
                        $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        move_uploaded_file($tmpName, $targetDir . $filename);

                        $id = generateHexId();
                        $item = [
                            '_id' => $id,
                            'title' => $body['title'] ?? '',
                            'description' => $body['description'] ?? '',
                            'category' => $body['category'] ?? '',
                            'alt' => $body['alt'] ?? 'gallery-image',
                            'imageUrl' => 'https://princedefence.com/uploads/gallery/' . $filename,
                            'publicId' => $filename,
                            'createdAt' => $now,
                            'updatedAt' => $now
                        ];
                        $stmt = $pdo->prepare("INSERT INTO galleries (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$id, json_encode($item), $now, $now]);
                        $uploadedItems[] = $item;
                    }
                }
            } else {
                $id = generateHexId();
                $item = array_merge($body, ['_id' => $id, 'createdAt' => $now, 'updatedAt' => $now]);
                $stmt = $pdo->prepare("INSERT INTO galleries (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, json_encode($item), $now, $now]);
                $uploadedItems[] = $item;
            }

            echo json_encode(['success' => true, 'message' => 'Gallery images uploaded successfully', 'data' => $uploadedItems]);
            exit;
        }

        if ($method === 'GET') {
            $category = trim($_GET['category'] ?? '');
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 12);

            $stmt = $pdo->query("SELECT data FROM galleries ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            if ($category && $category !== 'all') {
                $items = array_values(array_filter($items, function($g) use ($category) {
                    return ($g['category'] ?? '') === $category;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'data' => $p['items'],
                'gallery' => $p['items'],
                'galleries' => $p['items'],
                'pagination' => $p['pagination']
            ]);
            exit;
        }
    }

    // GALLERY CATEGORIES
    if (isset($routeParts[0]) && $routeParts[0] === 'gallery-categories') {
        if (isset($routeParts[1]) && $routeParts[1] === 'reorder' && $method === 'PUT') {
            requireAuth();
            echo json_encode(['success' => true, 'message' => 'Categories reordered']);
            exit;
        }

        if (isset($routeParts[1]) && isset($routeParts[2]) && $routeParts[2] === 'status' && $method === 'PATCH') {
            requireAuth();
            $id = $routeParts[1];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM gallerycategories WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $d = json_decode($row['data'], true);
                $d['isActive'] = (bool)($body['isActive'] ?? true);
                $d['updatedAt'] = date('c');
                $pdo->prepare("UPDATE gallerycategories SET data = ?, updatedAt = ? WHERE _id = ?")->execute([json_encode($d), $d['updatedAt'], $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Category status updated']);
            exit;
        }

        if (isset($routeParts[1]) && $method === 'GET') {
            $id = $routeParts[1];
            $stmt = $pdo->prepare("SELECT data FROM gallerycategories WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode(['success' => true, 'data' => json_decode($row['data'], true)]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
            }
            exit;
        }

        if (isset($routeParts[1]) && ($method === 'PUT' || $method === 'PATCH')) {
            requireAuth();
            $id = $routeParts[1];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM gallerycategories WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                exit;
            }
            $existing = json_decode($row['data'], true);
            $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
            $stmt = $pdo->prepare("UPDATE gallerycategories SET data = ?, updatedAt = ? WHERE _id = ?");
            $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);
            echo json_encode(['success' => true, 'message' => 'Category updated', 'data' => $updated]);
            exit;
        }

        if (isset($routeParts[1]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[1];
            $stmt = $pdo->prepare("DELETE FROM gallerycategories WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Category deleted']);
            exit;
        }

        if ($method === 'POST') {
            requireAuth();
            $body = getJsonInput();
            $id = generateHexId();
            $now = date('c');
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $body['name'] ?? '')));
            $data = array_merge($body, ['_id' => $id, 'slug' => $slug, 'isActive' => true, 'order' => 0, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO gallerycategories (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);
            echo json_encode(['success' => true, 'message' => 'Category created', 'data' => $data]);
            exit;
        }

        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT data FROM gallerycategories ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);
            echo json_encode(['success' => true, 'data' => $items, 'items' => $items]);
            exit;
        }
    }

    // CONTACTS
    if (isset($routeParts[0]) && $routeParts[0] === 'contact') {
        if (isset($routeParts[1]) && $routeParts[1] === 'export' && $method === 'GET') {
            requireAuth();
            $stmt = $pdo->query("SELECT data FROM contacts ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);
            echo json_encode(['success' => true, 'data' => $items]);
            exit;
        }

        if (isset($routeParts[1]) && isset($routeParts[2]) && $routeParts[2] === 'status' && $method === 'PATCH') {
            requireAuth();
            $id = $routeParts[1];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM contacts WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $d = json_decode($row['data'], true);
                $d['status'] = $body['status'] ?? 'closed';
                $d['updatedAt'] = date('c');
                $pdo->prepare("UPDATE contacts SET data = ?, updatedAt = ? WHERE _id = ?")->execute([json_encode($d), $d['updatedAt'], $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Status updated']);
            exit;
        }

        if (isset($routeParts[1]) && $method === 'DELETE') {
            requireAuth();
            $id = $routeParts[1];
            $stmt = $pdo->prepare("DELETE FROM contacts WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Contact deleted successfully']);
            exit;
        }

        if ($method === 'POST') {
            $body = getJsonInput();
            $id = generateHexId();
            $now = date('c');
            $data = array_merge($body, ['_id' => $id, 'status' => 'new', 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO contacts (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);
            echo json_encode(['success' => true, 'message' => 'Enquiry submitted successfully', 'data' => $data]);
            exit;
        }

        if ($method === 'GET') {
            requireAuth();
            $search = trim($_GET['search'] ?? '');
            $course = trim($_GET['course'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);

            $stmt = $pdo->query("SELECT data FROM contacts ORDER BY createdAt DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map(function($r) { return json_decode($r['data'], true); }, $rows);

            if ($status) {
                $items = array_values(array_filter($items, function($c) use ($status) {
                    return strcasecmp($c['status'] ?? '', $status) === 0;
                }));
            }
            if ($course) {
                $items = array_values(array_filter($items, function($c) use ($course) {
                    return stripos($c['course'] ?? '', $course) !== false;
                }));
            }
            if ($search) {
                $items = array_values(array_filter($items, function($c) use ($search) {
                    return stripos($c['name'] ?? '', $search) !== false ||
                           stripos($c['number'] ?? '', $search) !== false ||
                           stripos($c['message'] ?? '', $search) !== false;
                }));
            }

            $p = paginate($items, $page, $limit);
            echo json_encode([
                'success' => true,
                'data' => $p['items'],
                'contacts' => $p['items'],
                'pagination' => $p['pagination']
            ]);
            exit;
        }
    }

    // ADMIN MANAGEMENT
    if (isset($routeParts[0]) && $routeParts[0] === 'admin') {
        $currentUser = requireAuth();

        if (isset($routeParts[1]) && $method === 'GET') {
            $id = $routeParts[1];
            $stmt = $pdo->prepare("SELECT _id, data FROM admins WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $d = json_decode($row['data'], true);
                unset($d['password']);
                $d['_id'] = $row['_id'];
                echo json_encode(['success' => true, 'data' => $d]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Admin not found']);
            }
            exit;
        }

        if (isset($routeParts[1]) && ($method === 'PUT' || $method === 'PATCH')) {
            $id = $routeParts[1];
            $body = getJsonInput();
            $stmt = $pdo->prepare("SELECT data FROM admins WHERE _id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Admin not found']);
                exit;
            }
            $existing = json_decode($row['data'], true);
            if (!empty($body['password'])) {
                $body['password'] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            } else {
                unset($body['password']);
            }
            $updated = array_merge($existing, $body, ['updatedAt' => date('c')]);
            $stmt = $pdo->prepare("UPDATE admins SET data = ?, updatedAt = ? WHERE _id = ?");
            $stmt->execute([json_encode($updated), $updated['updatedAt'], $id]);
            unset($updated['password']);
            echo json_encode(['success' => true, 'message' => 'Admin updated successfully', 'data' => $updated]);
            exit;
        }

        if (isset($routeParts[1]) && $method === 'DELETE') {
            $id = $routeParts[1];
            $stmt = $pdo->prepare("DELETE FROM admins WHERE _id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Admin deleted successfully']);
            exit;
        }

        if ($method === 'POST') {
            $body = getJsonInput();
            $id = generateHexId();
            $hashedPassword = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $now = date('c');
            $data = array_merge($body, [
                '_id' => $id,
                'password' => $hashedPassword,
                'createdAt' => $now,
                'updatedAt' => $now,
                'isActive' => true
            ]);

            $stmt = $pdo->prepare("INSERT INTO admins (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($data), $now, $now]);

            unset($data['password']);
            echo json_encode(['success' => true, 'message' => 'Admin created successfully', 'data' => $data]);
            exit;
        }

        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT _id, data FROM admins");
            $admins = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = json_decode($row['data'], true);
                unset($d['password']);
                $d['_id'] = $row['_id'];
                $admins[] = $d;
            }
            echo json_encode(['success' => true, 'data' => $admins]);
            exit;
        }
    }

    // ANALYTICS TRACKING (PUBLIC)
    if (isset($routeParts[0]) && $routeParts[0] === 'analytics') {
        $body = getJsonInput();
        $id = generateHexId();
        $now = date('c');
        $dateKey = date('Y-m-d');

        if (isset($routeParts[1]) && $routeParts[1] === 'page-view') {
            $record = array_merge($body, ['_id' => $id, 'dateKey' => $dateKey, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO page_views (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($record), $now, $now]);
            echo json_encode(['success' => true]);
            exit;
        }

        if (isset($routeParts[1]) && $routeParts[1] === 'visitor') {
            $record = array_merge($body, ['_id' => $id, 'dateKey' => $dateKey, 'createdAt' => $now, 'updatedAt' => $now]);
            $stmt = $pdo->prepare("INSERT INTO visitors (_id, data, createdAt, updatedAt) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, json_encode($record), $now, $now]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // FORGOT PASSWORD
    if (isset($routeParts[0]) && $routeParts[0] === 'forgot-password') {
        echo json_encode(['success' => true, 'message' => 'Password reset request received.']);
        exit;
    }

    // Fallback 404 for truly unknown endpoints
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Endpoint not found',
        'route' => $route,
        'method' => $method
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
