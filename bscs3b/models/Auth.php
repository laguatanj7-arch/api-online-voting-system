<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
  private $pdo;
  
  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  private function generateJWT($userId, $username, $role) {
    $key = $_ENV['JWT_SECRET'];
    $exp = time() + (60 * 60 * 24); // 24 hours
    $payload = [
      "user_id" => $userId,
      "username" => $username,
      "role" => $role,
      "exp" => $exp
    ];
    return JWT::encode($payload, $key, "HS256");
  }

  public function register($d) {
    if (!isset($d->username) || !isset($d->password) || !isset($d->phone) || !isset($d->address)) {
        http_response_code(400);
        return ["error" => "Missing required fields (username, password, phone, address)."];
    }

    $un = trim($d->username);
    $pw = trim($d->password);
    $phone = trim($d->phone);
    $address = trim($d->address);

    // Advanced Validation
    if (strlen($un) < 4 || strlen($un) > 50) {
        http_response_code(400);
        return ["error" => "Username must be between 4 and 50 characters."];
    }
    if (strlen($pw) < 8) {
        http_response_code(400);
        return ["error" => "Password must be at least 8 characters long."];
    }
    if (empty($phone) || !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        http_response_code(400);
        return ["error" => "Invalid phone number format."];
    }
    if (strlen($address) < 5) {
        http_response_code(400);
        return ["error" => "Address is too short (minimum 5 characters)."];
    }

    $pwHash = password_hash($pw, PASSWORD_DEFAULT);

    // Phone and address are stored together as one AES-256-GCM encrypted JSON payload.
    $sensitiveData = json_encode([
        'phone' => $d->phone,
        'address' => $d->address
    ]);
    
    try {
        $encryptedData = Security::encrypt($sensitiveData);
    } catch (Exception $e) {
        http_response_code(500);
        return ["error" => "Encryption failed: " . $e->getMessage()];
    }

    // Default to voter, but keep admin when the registration endpoint explicitly sends it.
    $role = isset($d->role) && $d->role === 'admin' ? 'admin' : 'voter';

    // Insert to DB
    $sql = "INSERT INTO users (username, password, role, encrypted_data, auth_tag, iv) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $this->pdo->prepare($sql);
    
    try {
        $stmt->execute([
            $un, 
            $pwHash, 
            $role, 
            $encryptedData['ciphertext'], 
            $encryptedData['tag'], 
            $encryptedData['iv']
        ]);
        http_response_code(201);
        return ["message" => "User registered successfully."];
    } catch (\PDOException $e) {
        http_response_code(409);
        return ["error" => "Username already exists or database error.", "details" => $e->getMessage()];
    }
  }

  public function login($d) {
    if (!isset($d->username) || !isset($d->password)) {
        http_response_code(400);
        return ["error" => "Username and password are required."];
    }

    $un = $d->username;
    $pw = $d->password;
    
    $stmt = $this->pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->execute([$un]);
    $user = $stmt->fetch();

    if ($user && password_verify($pw, $user['password'])) {
      $token = $this->generateJWT($user['id'], $user['username'], $user['role']);
      return [
          "message" => "Login successful",
          "token" => $token,
          "role" => $user['role']
      ];
    } else {
      http_response_code(401);
      return ["error" => "Invalid username or password"];
    }
  }
}
