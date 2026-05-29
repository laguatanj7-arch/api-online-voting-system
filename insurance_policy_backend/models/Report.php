<?php

class Report {
  private \PDO $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function claimsStatus() {
    $stmt = $this->pdo->query(
      "SELECT status, COUNT(*) AS total_claims, COALESCE(SUM(claim_amount), 0) AS total_amount
       FROM claims
       GROUP BY status
       ORDER BY status"
    );

    return ['claims_status' => $stmt->fetchAll()];
  }

  public function premiumCollection() {
    $stmt = $this->pdo->query(
      "SELECT status, COUNT(*) AS total_policies, COALESCE(SUM(premium_amount), 0) AS total_premium
       FROM policies
       GROUP BY status
       ORDER BY status"
    );

    return ['premium_collection' => $stmt->fetchAll()];
  }
}
