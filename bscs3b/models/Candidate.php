<?php

class Candidate {
  private $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function create($data) {
    if (!isset($data->election_id) || !isset($data->name) || !isset($data->position)) {
        http_response_code(400);
        return ["error" => "election_id, name, and position are required."];
    }

    $name = trim($data->name);
    $position = trim($data->position);

    if (!is_numeric($data->election_id) || $data->election_id <= 0) {
        http_response_code(400);
        return ["error" => "election_id must be a positive integer."];
    }
    if (empty($name) || strlen($name) > 100) {
        http_response_code(400);
        return ["error" => "Name must be between 1 and 100 characters."];
    }
    if (empty($position) || strlen($position) > 100) {
        http_response_code(400);
        return ["error" => "Position must be between 1 and 100 characters."];
    }

    $duplicate = $this->pdo->prepare("SELECT id FROM candidates WHERE election_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
    $duplicate->execute([$data->election_id, $name]);
    if ($duplicate->fetch()) {
        http_response_code(409);
        return ["error" => "Candidate name already exists in this election."];
    }

    $sql = "INSERT INTO candidates (election_id, name, position) VALUES (?, ?, ?)";
    $stmt = $this->pdo->prepare($sql);
    
    try {
        $stmt->execute([
            $data->election_id,
            $name,
            $position
        ]);
        http_response_code(201);
        return ["message" => "Candidate added successfully.", "id" => $this->pdo->lastInsertId()];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error. Check if election_id exists.", "details" => $e->getMessage()];
    }
  }

  public function createBulk($data) {
    $items = null;
    if (is_array($data)) {
        $items = $data;
    } elseif (isset($data->candidates) && is_array($data->candidates)) {
        $items = $data->candidates;
    }

    if (!$items || count($items) === 0) {
        http_response_code(400);
        return ["error" => "Send a non-empty candidates array."];
    }

    $seen = [];
    $validated = [];
    foreach ($items as $index => $candidate) {
        if (!isset($candidate->election_id) || !isset($candidate->name) || !isset($candidate->position)) {
            http_response_code(400);
            return ["error" => "Each candidate needs election_id, name, and position.", "index" => $index];
        }

        $electionId = $candidate->election_id;
        $name = trim($candidate->name);
        $position = trim($candidate->position);

        if (!is_numeric($electionId) || $electionId <= 0) {
            http_response_code(400);
            return ["error" => "election_id must be a positive integer.", "index" => $index];
        }
        if (empty($name) || strlen($name) > 100) {
            http_response_code(400);
            return ["error" => "Name must be between 1 and 100 characters.", "index" => $index];
        }
        if (empty($position) || strlen($position) > 100) {
            http_response_code(400);
            return ["error" => "Position must be between 1 and 100 characters.", "index" => $index];
        }

        $key = $electionId . "|" . strtolower($name);
        if (isset($seen[$key])) {
            http_response_code(409);
            return ["error" => "Duplicate candidate in request.", "name" => $name, "index" => $index];
        }
        $seen[$key] = true;
        $validated[] = [
            "election_id" => $electionId,
            "name" => $name,
            "position" => $position
        ];
    }

    try {
        $this->pdo->beginTransaction();

        $electionStmt = $this->pdo->prepare("SELECT id FROM elections WHERE id = ? LIMIT 1");
        $duplicateStmt = $this->pdo->prepare("SELECT id FROM candidates WHERE election_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $insertStmt = $this->pdo->prepare("INSERT INTO candidates (election_id, name, position) VALUES (?, ?, ?)");
        $created = [];

        foreach ($validated as $index => $candidate) {
            $electionStmt->execute([$candidate["election_id"]]);
            if (!$electionStmt->fetch()) {
                $this->pdo->rollBack();
                http_response_code(404);
                return ["error" => "Election not found.", "election_id" => $candidate["election_id"], "index" => $index];
            }

            $duplicateStmt->execute([$candidate["election_id"], $candidate["name"]]);
            if ($duplicateStmt->fetch()) {
                $this->pdo->rollBack();
                http_response_code(409);
                return ["error" => "Candidate name already exists in this election.", "name" => $candidate["name"], "index" => $index];
            }

            $insertStmt->execute([$candidate["election_id"], $candidate["name"], $candidate["position"]]);
            $created[] = [
                "id" => $this->pdo->lastInsertId(),
                "election_id" => $candidate["election_id"],
                "name" => $candidate["name"],
                "position" => $candidate["position"]
            ];
        }

        $this->pdo->commit();
        http_response_code(201);
        return [
            "message" => "Candidates added successfully.",
            "count" => count($created),
            "candidates" => $created
        ];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }

  public function getByElection($electionId) {
    $stmt = $this->pdo->prepare("SELECT id, election_id, name, position, created_at FROM candidates WHERE election_id = ?");
    $stmt->execute([$electionId]);
    return $stmt->fetchAll();
  }

  public function update($id, $data) {
    if (!is_numeric($id) || $id <= 0) {
        http_response_code(400);
        return ["error" => "Valid candidate ID is required."];
    }
    if (!isset($data->election_id) || !isset($data->name) || !isset($data->position)) {
        http_response_code(400);
        return ["error" => "election_id, name, and position are required."];
    }

    if (!is_numeric($data->election_id) || $data->election_id <= 0) {
        http_response_code(400);
        return ["error" => "election_id must be a positive integer."];
    }

    $name = trim($data->name);
    $position = trim($data->position);

    if (empty($name) || strlen($name) > 100) {
        http_response_code(400);
        return ["error" => "Name must be between 1 and 100 characters."];
    }
    if (empty($position) || strlen($position) > 100) {
        http_response_code(400);
        return ["error" => "Position must be between 1 and 100 characters."];
    }

    $election = $this->pdo->prepare("SELECT id FROM elections WHERE id = ?");
    $election->execute([$data->election_id]);
    if (!$election->fetch()) {
        http_response_code(404);
        return ["error" => "Election not found."];
    }

    $candidate = $this->pdo->prepare("SELECT election_id FROM candidates WHERE id = ?");
    $candidate->execute([$id]);
    $current = $candidate->fetch();
    if (!$current) {
        http_response_code(404);
        return ["error" => "Candidate not found."];
    }

    $duplicate = $this->pdo->prepare("SELECT id FROM candidates WHERE election_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
    $duplicate->execute([$data->election_id, $name, $id]);
    if ($duplicate->fetch()) {
        http_response_code(409);
        return ["error" => "Candidate name already exists in this election."];
    }

    try {
        $this->pdo->beginTransaction();
        if ((int) $current['election_id'] !== (int) $data->election_id) {
            $this->pdo->prepare("DELETE FROM votes WHERE candidate_id = ?")->execute([$id]);
        }
        $stmt = $this->pdo->prepare("UPDATE candidates SET election_id = ?, name = ?, position = ? WHERE id = ?");
        $stmt->execute([$data->election_id, $name, $position, $id]);
        $this->pdo->commit();
        return ["message" => "Candidate updated successfully."];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }

  public function delete($id) {
    if (!is_numeric($id) || $id <= 0) {
        http_response_code(400);
        return ["error" => "Valid candidate ID is required."];
    }

    try {
        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM votes WHERE candidate_id = ?")->execute([$id]);
        $stmt = $this->pdo->prepare("DELETE FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $this->pdo->commit();
        return ["message" => "Candidate deleted successfully."];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }
}
