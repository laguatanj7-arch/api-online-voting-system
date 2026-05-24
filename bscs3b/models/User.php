<?php

class User {
  private $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function getProfile($userId) {
    $stmt = $this->pdo->prepare("SELECT id, username, role, encrypted_data, auth_tag, iv FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        return ["error" => "User not found"];
    }

    $profile = [
        "id" => $user['id'],
        "username" => $user['username'],
        "role" => $user['role'],
        "phone" => null,
        "address" => null
    ];

    if ($user['encrypted_data'] && $user['iv'] && $user['auth_tag']) {
        try {
            $decryptedJson = Security::decrypt($user['encrypted_data'], $user['iv'], $user['auth_tag']);
            $sensitiveData = json_decode($decryptedJson, true);
            if ($sensitiveData) {
                $profile['phone'] = $sensitiveData['phone'] ?? null;
                $profile['address'] = $sensitiveData['address'] ?? null;
            }
        } catch (Exception $e) {
            // Decryption failed (graceful handling as per requirements)
            $profile['decryption_error'] = "Could not decrypt sensitive data.";
        }
    }

    return $profile;
  }

  public function updateProfile($userId, $data) {
    // We only allow updating phone and address for this example
    if (!isset($data->phone) || !isset($data->address)) {
        http_response_code(400);
        return ["error" => "Phone and address are required to update profile."];
    }

    $phone = trim($data->phone);
    $address = trim($data->address);

    if (empty($phone) || !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        http_response_code(400);
        return ["error" => "Invalid phone number format."];
    }
    if (strlen($address) < 5) {
        http_response_code(400);
        return ["error" => "Address is too short (minimum 5 characters)."];
    }

    $sensitiveData = json_encode([
        'phone' => $phone,
        'address' => $address
    ]);
    
    try {
        $encryptedData = Security::encrypt($sensitiveData);
    } catch (Exception $e) {
        http_response_code(500);
        return ["error" => "Encryption failed: " . $e->getMessage()];
    }

    $sql = "UPDATE users SET encrypted_data = ?, iv = ?, auth_tag = ? WHERE id = ?";
    $stmt = $this->pdo->prepare($sql);
    
    try {
        $stmt->execute([
            $encryptedData['ciphertext'],
            $encryptedData['iv'],
            $encryptedData['tag'],
            $userId
        ]);
        return ["message" => "Profile updated successfully."];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }

  public function changePassword($userId, $data) {
    if (!isset($data->current_password) || !isset($data->new_password) || !isset($data->confirm_password)) {
        http_response_code(400);
        return ["error" => "Current password, new password, and confirmation are required."];
    }

    $currentPassword = trim($data->current_password);
    $newPassword = trim($data->new_password);
    $confirmPassword = trim($data->confirm_password);

    if (strlen($newPassword) < 8) {
        http_response_code(400);
        return ["error" => "New password must be at least 8 characters long."];
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        return ["error" => "New password and confirmation do not match."];
    }

    $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        return ["error" => "User not found"];
    }

    if (!password_verify($currentPassword, $user['password'])) {
        http_response_code(401);
        return ["error" => "Current password is incorrect."];
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

    try {
        $update->execute([$passwordHash, $userId]);
        return ["message" => "Password changed successfully."];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }
}
