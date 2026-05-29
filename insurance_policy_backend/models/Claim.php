<?php

class Claim {
  private \PDO $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function create($user, $data) {
    foreach (['policy_id', 'incident_date', 'claim_amount', 'incident_location', 'incident_description'] as $field) {
      if (!isset($data->$field) || trim((string)$data->$field) === '') {
        http_response_code(400);
        return ['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required.'];
      }
    }

    if (!is_numeric($data->claim_amount) || (float)$data->claim_amount <= 0) {
      http_response_code(400);
      return ['error' => 'Claim amount must be greater than zero.'];
    }

    $policyStmt = $this->pdo->prepare("SELECT id, customer_id FROM policies WHERE id = ?");
    $policyStmt->execute([(int)$data->policy_id]);
    $policy = $policyStmt->fetch();
    if (!$policy) {
      http_response_code(404);
      return ['error' => 'Policy not found.'];
    }
    if ($user['role'] !== 'admin' && (int)$policy['customer_id'] !== (int)$user['user_id']) {
      http_response_code(403);
      return ['error' => 'You cannot file a claim for this policy.'];
    }

    try {
      $encrypted = Security::encryptJson([
        'incident_location' => trim($data->incident_location),
        'incident_description' => trim($data->incident_description)
      ]);

      $stmt = $this->pdo->prepare(
        "INSERT INTO claims (policy_id, customer_id, incident_date, claim_amount, status, claim_data_ciphertext, claim_data_iv, claim_data_tag)
         VALUES (?, ?, ?, ?, 'submitted', ?, ?, ?)"
      );
      $stmt->execute([
        (int)$data->policy_id,
        (int)$policy['customer_id'],
        $data->incident_date,
        (float)$data->claim_amount,
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag']
      ]);

      http_response_code(201);
      return ['message' => 'Claim submitted successfully.', 'claim_id' => (int)$this->pdo->lastInsertId()];
    } catch (Exception $e) {
      http_response_code(500);
      return ['error' => 'Could not encrypt claim details.'];
    }
  }

  public function getById($claimId, $user) {
    $stmt = $this->pdo->prepare(
      "SELECT c.*, p.policy_number
       FROM claims c JOIN policies p ON p.id = c.policy_id
       WHERE c.id = ?"
    );
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();

    if (!$claim) {
      http_response_code(404);
      return ['error' => 'Claim not found.'];
    }
    if ($user['role'] !== 'admin' && (int)$claim['customer_id'] !== (int)$user['user_id']) {
      http_response_code(403);
      return ['error' => 'You cannot access this claim.'];
    }

    return ['claim' => $this->formatClaim($claim)];
  }

  public function getAll() {
    $stmt = $this->pdo->query(
      "SELECT c.*, p.policy_number, u.name AS customer_name
       FROM claims c
       JOIN policies p ON p.id = c.policy_id
       JOIN users u ON u.id = c.customer_id
       ORDER BY c.created_at DESC"
    );

    return ['claims' => array_map([$this, 'formatClaim'], $stmt->fetchAll())];
  }

  private function formatClaim($claim) {
    try {
      $data = Security::decryptJson($claim['claim_data_ciphertext'], $claim['claim_data_iv'], $claim['claim_data_tag']);
    } catch (Exception $e) {
      $data = ['decryption_error' => 'Sensitive claim data could not be decrypted.'];
    }

    return [
      'id' => (int)$claim['id'],
      'policy_id' => (int)$claim['policy_id'],
      'policy_number' => $claim['policy_number'] ?? null,
      'customer_id' => (int)$claim['customer_id'],
      'customer_name' => $claim['customer_name'] ?? null,
      'incident_date' => $claim['incident_date'],
      'claim_amount' => (float)$claim['claim_amount'],
      'status' => $claim['status'],
      'incident_location' => $data['incident_location'] ?? null,
      'incident_description' => $data['incident_description'] ?? null,
      'decryption_error' => $data['decryption_error'] ?? null,
      'created_at' => $claim['created_at']
    ];
  }
}
