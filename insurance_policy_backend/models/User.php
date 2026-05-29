<?php

class User {
  private \PDO $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function getProfile($userId) {
    $stmt = $this->pdo->prepare(
      "SELECT id, name, role, personal_data_ciphertext, personal_data_iv, personal_data_tag, created_at
       FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      http_response_code(404);
      return ['error' => 'User not found.'];
    }

    try {
      $personal = Security::decryptJson($user['personal_data_ciphertext'], $user['personal_data_iv'], $user['personal_data_tag']);
    } catch (Exception $e) {
      http_response_code(500);
      return ['error' => 'Sensitive profile data could not be decrypted.'];
    }

    return [
      'id' => (int)$user['id'],
      'name' => $user['name'],
      'role' => $user['role'],
      'email' => $personal['email'] ?? null,
      'phone' => $personal['phone'] ?? null,
      'address' => $personal['address'] ?? null,
      'created_at' => $user['created_at']
    ];
  }

  public function updateProfile($userId, $data) {
    foreach (['name', 'email', 'phone', 'address'] as $field) {
      if (!isset($data->$field) || trim((string)$data->$field) === '') {
        http_response_code(400);
        return ['error' => ucfirst($field) . ' is required.'];
      }
    }

    $email = strtolower(trim($data->email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      return ['error' => 'A valid email address is required.'];
    }

    try {
      $encrypted = Security::encryptJson([
        'email' => $email,
        'phone' => trim($data->phone),
        'address' => trim($data->address)
      ]);

      $stmt = $this->pdo->prepare(
        "UPDATE users
         SET name = ?, email_hash = ?, personal_data_ciphertext = ?, personal_data_iv = ?, personal_data_tag = ?
         WHERE id = ?"
      );
      $stmt->execute([
        trim($data->name),
        hash('sha256', $email),
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag'],
        $userId
      ]);

      return ['message' => 'Profile updated successfully.'];
    } catch (\PDOException $e) {
      http_response_code($e->getCode() === '23000' ? 409 : 500);
      return ['error' => 'Could not update profile.'];
    } catch (Exception $e) {
      http_response_code(500);
      return ['error' => 'Could not encrypt profile data.'];
    }
  }
}
