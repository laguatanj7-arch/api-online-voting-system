<?php

class Report {
  private $pdo;

  public function __construct(\PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function getVoteCount($electionId = null) {
    $sql = "
      SELECT c.name as candidate_name, c.position, e.title as election_title, COUNT(v.id) as total_votes
      FROM candidates c
      JOIN elections e ON c.election_id = e.id
      LEFT JOIN votes v ON c.id = v.candidate_id
    ";
    
    $params = [];
    if ($electionId) {
        $sql .= " WHERE c.election_id = ?";
        $params[] = $electionId;
    }
    
    $sql .= " GROUP BY c.id ORDER BY total_votes DESC";
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
  }

  public function getTurnout($electionId = null) {
    // Total users who can vote
    $stmt = $this->pdo->query("SELECT COUNT(id) as total_users FROM users WHERE role = 'voter'");
    $totalUsers = $stmt->fetch()['total_users'];

    $sql = "
      SELECT e.id, e.title, COUNT(DISTINCT v.user_id) as users_voted
      FROM elections e
      LEFT JOIN votes v ON e.id = v.election_id
    ";
    
    $params = [];
    if ($electionId) {
        $sql .= " WHERE e.id = ?";
        $params[] = $electionId;
    }
    
    $sql .= " GROUP BY e.id";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $elections = $stmt->fetchAll();

    $turnout = [];
    foreach ($elections as $election) {
        $percentage = $totalUsers > 0 ? round(($election['users_voted'] / $totalUsers) * 100, 2) : 0;
        $turnout[] = [
            "election" => $election['title'],
            "users_voted" => $election['users_voted'],
            "total_users" => $totalUsers,
            "turnout_percentage" => $percentage . "%"
        ];
    }
    return $turnout;
  }
}
