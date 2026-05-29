<?php

class Policy {
  private \PDO $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function create($data) {
    foreach (['policy_number', 'customer_id', 'type', 'premium_amount', 'coverage_amount', 'start_date', 'end_date'] as $field) {
      if (!isset($data->$field) || trim((string)$data->$field) === '') {
        http_response_code(400);
        return ['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required.'];
      }
    }

    if (!is_numeric($data->premium_amount) || !is_numeric($data->coverage_amount)) {
      http_response_code(400);
      return ['error' => 'Premium and coverage amounts must be numeric.'];
    }

    try {
      $stmt = $this->pdo->prepare(
        "INSERT INTO policies (policy_number, customer_id, type, premium_amount, coverage_amount, status, start_date, end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $stmt->execute([
        trim($data->policy_number),
        (int)$data->customer_id,
        trim($data->type),
        (float)$data->premium_amount,
        (float)$data->coverage_amount,
        isset($data->status) ? trim($data->status) : 'active',
        $data->start_date,
        $data->end_date
      ]);

      http_response_code(201);
      return ['message' => 'Policy created successfully.', 'policy_id' => (int)$this->pdo->lastInsertId()];
    } catch (\PDOException $e) {
      http_response_code($e->getCode() === '23000' ? 409 : 500);
      return ['error' => 'Could not create policy. Check customer and policy number.'];
    }
  }

  public function getAll($user) {
    if ($user['role'] === 'admin') {
      $stmt = $this->pdo->query(
        "SELECT p.*, u.name AS customer_name
         FROM policies p JOIN users u ON u.id = p.customer_id
         ORDER BY p.created_at DESC"
      );
    } else {
      $stmt = $this->pdo->prepare("SELECT * FROM policies WHERE customer_id = ? ORDER BY created_at DESC");
      $stmt->execute([$user['user_id']]);
    }

    return ['policies' => array_map([$this, 'formatPolicy'], $stmt->fetchAll())];
  }

  public function getById($policyId, $user) {
    $stmt = $this->pdo->prepare(
      "SELECT p.*, u.name AS customer_name
       FROM policies p JOIN users u ON u.id = p.customer_id
       WHERE p.id = ?"
    );
    $stmt->execute([$policyId]);
    $policy = $stmt->fetch();

    if (!$policy) {
      http_response_code(404);
      return ['error' => 'Policy not found.'];
    }
    if ($user['role'] !== 'admin' && (int)$policy['customer_id'] !== (int)$user['user_id']) {
      http_response_code(403);
      return ['error' => 'You cannot access this policy.'];
    }

    return ['policy' => $this->formatPolicy($policy)];
  }

  private function formatPolicy($policy) {
    return [
      'id' => (int)$policy['id'],
      'policy_number' => $policy['policy_number'],
      'customer_id' => (int)$policy['customer_id'],
      'customer_name' => $policy['customer_name'] ?? null,
      'type' => $policy['type'],
      'premium_amount' => (float)$policy['premium_amount'],
      'coverage_amount' => (float)$policy['coverage_amount'],
      'status' => $policy['status'],
      'start_date' => $policy['start_date'],
      'end_date' => $policy['end_date'],
      'created_at' => $policy['created_at']
    ];
  }
}
