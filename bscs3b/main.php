<?php
require_once __DIR__ . "/functions.php";
require __DIR__ . "/vendor/autoload.php";
$env = Dotenv\Dotenv::createImmutable(__DIR__ ."/config/");
$env->load();

// Ensure output is JSON
header("Content-Type: application/json");

$allowedOrigins = [
    "http://127.0.0.1:5173",
    "http://localhost:5173",
    "http://127.0.0.1:5174",
    "http://localhost:5174",
    "http://127.0.0.1:4173",
    "http://localhost:4173"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit();
}


// Helper to check JWT and return payload
function getJwtPayload() {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
        try {
            $key = $_ENV['JWT_SECRET'];
            $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($key, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid or expired token"]);
            exit();
        }
    }
    
    http_response_code(401);
    echo json_encode(["error" => "Authorization header required"]);
    exit();
}

function requireAdmin() {
    $payload = getJwtPayload();
    if ($payload['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Admin access required"]);
        exit();
    }
    return $payload;
}

// Instantiate DB Connection
$db = new Connection();
$pdo = $db->connect();

// Ensure we have a valid PDO instance
if (!($pdo instanceof \PDO)) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Database connection failed"]);
    exit();
}

// Instantiate Models
$auth = new Auth($pdo);
$userModel = new User($pdo);
$electionModel = new Election($pdo);
$candidateModel = new Candidate($pdo);
$voteModel = new Vote($pdo);
$reportModel = new Report($pdo);

// Parse request
$param = explode("/", $_GET['params'] ?? '');
// Structure: api/module/action/optional_id
// So $param[0] = api, $param[1] = module, $param[2] = action

if ($param[0] !== 'api') {
    http_response_code(404);
    echo json_encode(["error" => "Not Found"]);
    exit();
}

$module = $param[1] ?? '';
$action = $param[2] ?? '';
$id_param = $param[3] ?? null;

$dt = json_decode(file_get_contents("php://input"));
$method = $_SERVER['REQUEST_METHOD'];

switch ($module) {
    case 'auth':
        if ($method === 'POST' && $action === 'register') {
            echo json_encode($auth->register($dt));
        } elseif ($method === 'POST' && $action === 'login') {
            echo json_encode($auth->login($dt));
        } else {
            http_response_code(404);
        }
        break;

    case 'users':
        if ($action === 'profile') {
            $payload = getJwtPayload();
            if ($method === 'GET') {
                echo json_encode($userModel->getProfile($payload['user_id']));
            } elseif ($method === 'PUT') {
                echo json_encode($userModel->updateProfile($payload['user_id'], $dt));
            } else {
                http_response_code(405); // Method Not Allowed
            }
        } elseif ($action === 'password') {
            $payload = getJwtPayload();
            if ($method === 'PUT') {
                echo json_encode($userModel->changePassword($payload['user_id'], $dt));
            } else {
                http_response_code(405);
            }
        } else {
            http_response_code(404);
        }
        break;

    case 'elections':
        if ($method === 'POST') {
            requireAdmin();
            echo json_encode($electionModel->create($dt));
        } elseif ($method === 'PUT' && $action) {
            requireAdmin();
            echo json_encode($electionModel->update($action, $dt));
        } elseif ($method === 'DELETE' && $action) {
            requireAdmin();
            echo json_encode($electionModel->delete($action));
        } elseif ($method === 'GET' && $action) {
            getJwtPayload();
            echo json_encode($electionModel->getById($action));
        } elseif ($method === 'GET') {
            getJwtPayload(); // Just ensure they are logged in
            echo json_encode($electionModel->getAll());
        } else {
            http_response_code(405);
        }
        break;

    case 'candidates':
        if ($method === 'POST' && $action === 'bulk') {
            requireAdmin();
            echo json_encode($candidateModel->createBulk($dt));
        } elseif ($method === 'POST') {
            requireAdmin();
            echo json_encode($candidateModel->create($dt));
        } elseif ($method === 'PUT' && $action) {
            requireAdmin();
            echo json_encode($candidateModel->update($action, $dt));
        } elseif ($method === 'DELETE' && $action) {
            requireAdmin();
            echo json_encode($candidateModel->delete($action));
        } elseif ($method === 'GET') {
            // action is election_id in this case
            getJwtPayload();
            $electionId = $action;
            if ($electionId) {
                echo json_encode($candidateModel->getByElection($electionId));
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Election ID is required"]);
            }
        } else {
            http_response_code(405);
        }
        break;

    case 'votes':
        if ($method === 'POST' && $action === 'cast') {
            $payload = getJwtPayload();
            if ($payload['role'] !== 'voter') {
                http_response_code(403);
                echo json_encode(["error" => "Only voters can cast votes."]);
                exit();
            }
            echo json_encode($voteModel->cast($payload['user_id'], $dt));
        } elseif ($method === 'GET' && $action === 'user') {
            $payload = getJwtPayload();
            $targetUserId = $id_param;
            // Admin can check anyone, user can only check themselves
            if ($payload['role'] === 'admin' || $payload['user_id'] == $targetUserId) {
                echo json_encode($voteModel->getByUser($targetUserId));
            } else {
                http_response_code(403);
                echo json_encode(["error" => "Forbidden"]);
            }
        } else {
            http_response_code(405);
        }
        break;

    case 'admin':
        requireAdmin();
        if ($method === 'GET' && $action === 'elections') {
            echo json_encode($electionModel->getAll());
        } elseif ($method === 'GET' && $action === 'votes') {
            // Not explicitly defined in models yet, but we can query all votes.
            $stmt = $pdo->query("SELECT * FROM votes ORDER BY timestamp DESC");
            echo json_encode($stmt->fetchAll());
        } else {
            http_response_code(404);
        }
        break;

    case 'reports':
        requireAdmin();
        if ($method === 'GET' && $action === 'vote-count') {
            echo json_encode($reportModel->getVoteCount());
        } elseif ($method === 'GET' && $action === 'turnout') {
            echo json_encode($reportModel->getTurnout());
        } else {
            http_response_code(404);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(["error" => "API endpoint not found"]);
        break;
}
