<?php
require_once __DIR__ . '/Participant.php';
require_once __DIR__ . '/DbConnection.php';

class Bill
{
  // 建立帳單
  public static function createBill($db, $data)
  {
    $sql = "INSERT INTO bills (group_id,total_amount,bill_name,payer_user_id,created_at) 
    VALUE (?,?,?,?,NOW())";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sdss", $data['groupId'], $data['amount'], $data['billName'], $data['payerId']);

    if (!$stmt->execute()) {
      error_log('Failed to create bill: ' . $stmt->error);
      $stmt->close();
      return 0;
    }

    $newBillid = $stmt->insert_id;
    $stmt->close();

    //參與者存入participants資料表
    if (is_array($data['participants']) && !empty($data['participants'])){
      foreach ($data['participants'] as $participantUserId){
        Participant::addParticipant($db,$newBillid,$participantUserId);
      }
    }

    return $newBillid;
  }
}
