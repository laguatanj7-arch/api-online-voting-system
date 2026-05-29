<?php
require_once __DIR__ . "/functions.php";
require __DIR__ . "/vendor/autoload.php";

$env = Dotenv\Dotenv::createImmutable(__DIR__ . "/config/");
$env->load();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit();
}

function sendJson($data) {
  try {
    $encrypted = Security::encryptJson(is_array($data) ? $data : ['data' => $data]);
    echo json_encode([
      'encrypted_data' => $encrypted['ciphertext'],
      'iv' => $encrypted['iv'],
      'auth_tag' => $encrypted['tag']
    ], JSON_UNESCAPED_SLASHES);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Encrypted response failed.'], JSON_UNESCAPED_SLASHES);
  }
  exit();
}

function readJsonPayload() {
  $raw = file_get_contents("php://input");
  if ($raw === '') {
    return (object)[];
  }

  $decoded = json_decode($raw);
  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    sendJson(['error' => 'Request body must be valid JSON.']);
  }

  if (isset($decoded->encrypted_data, $decoded->iv, $decoded->auth_tag)) {
    try {
      $payload = Security::decryptJson($decoded->encrypted_data, $decoded->iv, $decoded->auth_tag);
      return json_decode(json_encode($payload));
    } catch (Exception $e) {
      http_response_code(400);
      sendJson(['error' => 'Encrypted request payload could not be decrypted.']);
    }
  }

  return $decoded;
}

function currentUser() {
  $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
  $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    http_response_code(401);
    sendJson(['error' => 'Authorization Bearer token is required.']);
  }

  try {
    return Security::verifyJwt(trim($matches[1]));
  } catch (Exception $e) {
    http_response_code(401);
    sendJson(['error' => 'Invalid or expired token.']);
  }
}

function requireAdmin() {
  $user = currentUser();
  if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    sendJson(['error' => 'Admin access required.']);
  }

  return $user;
}

$db = new Connection();
$pdo = $db->connect();
if (!($pdo instanceof \PDO)) {
  http_response_code(500);
  sendJson(['error' => 'Database connection failed.']);
}

$auth = new Auth($pdo);
$users = new User($pdo);
$policies = new Policy($pdo);
$claims = new Claim($pdo);
$reports = new Report($pdo);

$param = explode("/", trim($_GET['params'] ?? '', "/"));
if (($param[0] ?? '') !== 'api') {
  http_response_code(404);
  sendJson(['error' => 'API endpoint not found.']);
}

$module = $param[1] ?? '';
$action = $param[2] ?? '';
$id = $param[2] ?? null;
$method = $_SERVER['REQUEST_METHOD'];
$body = readJsonPayload();

switch ($module) {
  case 'auth':
    if ($method === 'POST' && $action === 'register') {
      sendJson($auth->register($body));
    }
    if ($method === 'POST' && $action === 'login') {
      sendJson($auth->login($body));
    }
    break;

  case 'users':
    if ($action === 'profile') {
      $user = currentUser();
      if ($method === 'GET') {
        sendJson($users->getProfile($user['user_id']));
      }
      if ($method === 'PUT') {
        sendJson($users->updateProfile($user['user_id'], $body));
      }
      http_response_code(405);
      sendJson(['error' => 'Method not allowed.']);
    }
    break;

  case 'policies':
    if ($method === 'POST') {
      requireAdmin();
      sendJson($policies->create($body));
    }
    if ($method === 'GET' && $id) {
      sendJson($policies->getById((int)$id, currentUser()));
    }
    if ($method === 'GET') {
      sendJson($policies->getAll(currentUser()));
    }
    break;

  case 'claims':
    if ($method === 'POST') {
      sendJson($claims->create(currentUser(), $body));
    }
    if ($method === 'GET' && $id) {
      sendJson($claims->getById((int)$id, currentUser()));
    }
    break;

  case 'admin':
    requireAdmin();
    if ($method === 'GET' && $action === 'policies') {
      sendJson($policies->getAll(['role' => 'admin', 'user_id' => 0]));
    }
    if ($method === 'GET' && $action === 'claims') {
      sendJson($claims->getAll());
    }
    break;

  case 'reports':
    requireAdmin();
    if ($method === 'GET' && $action === 'claims-status') {
      sendJson($reports->claimsStatus());
    }
    if ($method === 'GET' && $action === 'premium-collection') {
      sendJson($reports->premiumCollection());
    }
    break;
}

http_response_code(404);
sendJson(['error' => 'API endpoint not found.']);
