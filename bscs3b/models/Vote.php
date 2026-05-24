<?php

class Vote {
  private $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function cast($userId, $data) {
    if (!isset($data->election_id)) {
        http_response_code(400);
        return ["error" => "election_id is required."];
    }

    if (!is_numeric($data->election_id) || $data->election_id <= 0) {
        http_response_code(400);
        return ["error" => "election_id must be a positive integer."];
    }

    $candidateIds = [];
    if (isset($data->votes) && is_array($data->votes)) {
        foreach ($data->votes as $vote) {
            if (isset($vote->candidate_id)) {
                $candidateIds[] = $vote->candidate_id;
            }
        }
    } elseif (isset($data->candidate_id)) {
        $candidateIds[] = $data->candidate_id;
    }

    $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
    if (empty($candidateIds)) {
        http_response_code(400);
        return ["error" => "At least one candidate_id is required."];
    }

    foreach ($candidateIds as $candidateId) {
        if ($candidateId <= 0) {
            http_response_code(400);
            return ["error" => "candidate_id must be a positive integer."];
        }
    }

    $stmt = $this->pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $stmt->execute([$data->election_id]);
    $election = $stmt->fetch();

    if (!$election) {
        http_response_code(404);
        return ["error" => "Election not found."];
    }

    $existingElectionStmt = $this->pdo->prepare("SELECT id FROM votes WHERE user_id = ? AND election_id = ? LIMIT 1");
    $existingElectionStmt->execute([$userId, $data->election_id]);
    if ($existingElectionStmt->fetch()) {
        http_response_code(409);
        return ["error" => "You have already voted in this election."];
    }

    $placeholders = implode(",", array_fill(0, count($candidateIds), "?"));
    $candidateStmt = $this->pdo->prepare("SELECT id, name, position FROM candidates WHERE election_id = ? AND id IN ($placeholders)");
    $candidateStmt->execute(array_merge([$data->election_id], $candidateIds));
    $candidates = $candidateStmt->fetchAll();

    if (count($candidates) !== count($candidateIds)) {
        http_response_code(400);
        return ["error" => "One or more selected candidates do not belong to this election."];
    }

    $positions = [];
    foreach ($candidates as $candidate) {
        $positionKey = strtolower(trim($candidate['position']));
        if (isset($positions[$positionKey])) {
            http_response_code(400);
            return ["error" => "Choose only one candidate per position."];
        }
        $positions[$positionKey] = $candidate['position'];
    }

    $allPositionsStmt = $this->pdo->prepare("SELECT COUNT(DISTINCT LOWER(TRIM(position))) as total_positions FROM candidates WHERE election_id = ?");
    $allPositionsStmt->execute([$data->election_id]);
    $totalPositions = (int) $allPositionsStmt->fetch()['total_positions'];

    if (count($positions) !== $totalPositions) {
        http_response_code(400);
        return ["error" => "Please choose one candidate for every position before submitting."];
    }
    
    try {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("INSERT INTO votes (user_id, election_id, candidate_id) VALUES (?, ?, ?)");
        foreach ($candidateIds as $candidateId) {
            $stmt->execute([
                $userId,
                $data->election_id,
                $candidateId
            ]);
        }
        $this->pdo->commit();
        http_response_code(201);
        return ["message" => "Vote cast successfully.", "total_votes" => count($candidateIds)];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        http_response_code(409); // Conflict
        return [
            "error" => "Vote could not be saved. Run database_update_per_position_votes.sql if your votes table still blocks multiple positions.",
            "details" => $e->getMessage()
        ];
    }
  }

  public function getByUser($userId) {
    $sql = "
      SELECT v.id, v.election_id, v.timestamp, e.title as election_title, c.name as candidate_name, c.position 
      FROM votes v
      JOIN elections e ON v.election_id = e.id
      JOIN candidates c ON v.candidate_id = c.id
      WHERE v.user_id = ?
      ORDER BY v.timestamp DESC
    ";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
  }
}
