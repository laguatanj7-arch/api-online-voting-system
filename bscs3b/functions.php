<?php
function execQuery($sql, $params, $pdo){
  $data = [];
  $stmt = $pdo->prepare($sql);
  try {
    $stmt->execute($params);
    if($stmt->rowCount() > 0 ) {
      if($res=$stmt->fetchAll()) {
        $data = $res;
      }
    }
    $stmt->closeCursor();
  } catch (\Throwable $th) {
    http_response_code(403);
  }
  return $data;
}