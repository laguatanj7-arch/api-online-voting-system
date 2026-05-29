<?php

class Auth {
  private \PDO $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function register($data) {
    foreach (['name', 'email', 'password', 'phone', 'address'] as $field) {
      if (!isset($data->$field) || trim((string)$data->$field) === '') {
        http_response_code(400);
        return ['error' => ucfirst($field) . ' is required.'];
      }
    }

    $name = trim($data->name);
    $email = strtolower(trim($data->email));
    $password = (string)$data->password;
    $phone = trim($data->phone);
    $address = trim($data->address);

    if (strlen($name) < 2 || strlen($name) > 100) {
      http_response_code(400);
      return ['error' => 'Name must be 2 to 100 characters.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      return ['error' => 'A valid email address is required.'];
    }
    if (strlen($password) < 8) {
      http_response_code(400);
      return ['error' => 'Password must be at least 8 characters.'];
    }
    if (!preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
      http_response_code(400);
      return ['error' => 'Phone number format is invalid.'];
    }
    if (strlen($address) < 5) {
      http_response_code(400);
      return ['error' => 'Address must be at least 5 characters.'];
    }

    try {
      $encrypted = Security::encryptJson([
        'email' => $email,
        'phone' => $phone,
        'address' => $address
      ]);

      $stmt = $this->pdo->prepare(
        "INSERT INTO users (name, email_hash, password_hash, role, personal_data_ciphertext, personal_data_iv, personal_data_tag)
         VALUES (?, ?, ?, 'customer', ?, ?, ?)"
      );
      $stmt->execute([
        $name,
        hash('sha256', $email),
        password_hash($password, PASSWORD_DEFAULT),
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag']
      ]);

      http_response_code(201);
      return ['message' => 'Account registered successfully.'];
    } catch (\PDOException $e) {
      http_response_code($e->getCode() === '23000' ? 409 : 500);
      return ['error' => 'Email is already registered or database error.'];
    } catch (Exception $e) {
      http_response_code(500);
      return ['error' => 'Could not encrypt personal data.'];
    }
  }

  public function login($data) {
    if (!isset($data->email, $data->password)) {
      http_response_code(400);
      return ['error' => 'Email and password are required.'];
    }

    $email = strtolower(trim($data->email));
    $stmt = $this->pdo->prepare("SELECT id, name, password_hash, role FROM users WHERE email_hash = ?");
    $stmt->execute([hash('sha256', $email)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify((string)$data->password, $user['password_hash'])) {
      http_response_code(401);
      return ['error' => 'Invalid email or password.'];
    }

    return [
      'message' => 'Login successful.',
      'token' => Security::createJwt([
        'user_id' => (int)$user['id'],
        'name' => $user['name'],
        'role' => $user['role']
      ]),
      'user' => [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'role' => $user['role']
      ]
    ];
  }
}
