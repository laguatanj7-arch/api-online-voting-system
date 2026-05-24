<?php

class Election {
  private $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function create($data) {
    if (!isset($data->title) || !isset($data->start_date) || !isset($data->end_date)) {
        http_response_code(400);
        return ["error" => "Title, start_date, and end_date are required."];
    }

    $title = trim($data->title);
    if (empty($title) || strlen($title) > 100) {
        http_response_code(400);
        return ["error" => "Title must be between 1 and 100 characters."];
    }

    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $data->start_date);
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $data->end_date);

    if (!$startDate || !$endDate) {
        http_response_code(400);
        return ["error" => "Invalid date format. Use YYYY-MM-DD HH:MM:SS"];
    }

    if ($startDate >= $endDate) {
        http_response_code(400);
        return ["error" => "Start date must be before end date."];
    }

    $sql = "INSERT INTO elections (title, description, start_date, end_date) VALUES (?, ?, ?, ?)";
    $stmt = $this->pdo->prepare($sql);
    
    try {
        $stmt->execute([
            $title,
            $data->description ?? '',
            $data->start_date,
            $data->end_date
        ]);
        http_response_code(201);
        return ["message" => "Election created successfully.", "id" => $this->pdo->lastInsertId()];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }

  public function getAll() {
    try {
        $stmt = $this->pdo->query("SELECT id, title, description, start_date, end_date, created_at FROM elections ORDER BY created_at DESC");
        $elections = $stmt->fetchAll();

        return [
            "message" => count($elections) > 0 ? "Elections loaded successfully." : "No elections found.",
            "data" => $elections
        ];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error while loading elections.", "details" => $e->getMessage()];
    }
  }

  public function getById($id) {
    if (!is_numeric($id) || $id <= 0) {
        http_response_code(400);
        return ["error" => "Valid election ID is required."];
    }

    try {
        $stmt = $this->pdo->prepare("SELECT id, title, description, start_date, end_date, created_at FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $election = $stmt->fetch();

        if (!$election) {
            http_response_code(404);
            return ["error" => "Election not found."];
        }

        return [
            "message" => "Election loaded successfully.",
            "data" => $election
        ];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error while loading election.", "details" => $e->getMessage()];
    }
  }

  private function validate($data) {
    if (!isset($data->title) || !isset($data->start_date) || !isset($data->end_date)) {
        http_response_code(400);
        return ["error" => "Title, start_date, and end_date are required."];
    }

    $title = trim($data->title);
    if (empty($title) || strlen($title) > 100) {
        http_response_code(400);
        return ["error" => "Title must be between 1 and 100 characters."];
    }

    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $data->start_date);
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $data->end_date);

    if (!$startDate || !$endDate) {
        http_response_code(400);
        return ["error" => "Invalid date format. Use YYYY-MM-DD HH:MM:SS"];
    }

    if ($startDate >= $endDate) {
        http_response_code(400);
        return ["error" => "Start date must be before end date."];
    }

    return [
        "title" => $title,
        "description" => $data->description ?? '',
        "start_date" => $data->start_date,
        "end_date" => $data->end_date
    ];
  }

  public function update($id, $data) {
    if (!is_numeric($id) || $id <= 0) {
        http_response_code(400);
        return ["error" => "Valid election ID is required."];
    }

    $validated = $this->validate($data);
    if (isset($validated["error"])) {
        return $validated;
    }

    try {
        $stmt = $this->pdo->prepare("UPDATE elections SET title = ?, description = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->execute([
            $validated["title"],
            $validated["description"],
            $validated["start_date"],
            $validated["end_date"],
            $id
        ]);
        return ["message" => "Election updated successfully."];
    } catch (\PDOException $e) {
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }

  public function delete($id) {
    if (!is_numeric($id) || $id <= 0) {
        http_response_code(400);
        return ["error" => "Valid election ID is required."];
    }

    try {
        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM votes WHERE election_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM candidates WHERE election_id = ?")->execute([$id]);
        $stmt = $this->pdo->prepare("DELETE FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $this->pdo->commit();
        return ["message" => "Election deleted successfully."];
    } catch (\PDOException $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        http_response_code(500);
        return ["error" => "Database error.", "details" => $e->getMessage()];
    }
  }
}
