<?php
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/MessageHandler.php';
require_once __DIR__ . '/../models/DbConnection.php';

class BillService
{
  public static function createBillSummaryFlexMessage($db, $billId, $isUpdate = false)
  {
    $bill = Bill::getBillById($db, $billId);
    if (!$bill) {
      return null;
    }

    // 取得付款人的名稱
    $payerProfile = MessageHandler::getProfile($bill['payer_user_id']);
    $payerName = $payerProfile['displayName'] ?? '未知用戶';

    // 取得參與分帳的成員清單
    $participants = Participant::getParticipantsByBillId($db, $billId);
    $participantNames = [];
    foreach ($participants as $participant) {
      $profile = MessageHandler::getProfile($participant['user_id']);
      if ($profile) {
        $participantNames[] = $profile['displayName'];
      }
    }
    $participantsList = implode(', ', $participantNames);

    $title = $bill['bill_name'];
    $altText = '新增帳單通知';
    if ($isUpdate) {
      $title = "帳單更新: " . $bill['bill_name'];
      $altText = '帳單已更新';
    }
    $flexMessage = [
      'type' => 'bubble',
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
          [
            'type' => 'text',
            'text' => $title,
            'weight' => 'bold',
            'size' => 'xl'
          ],
          [
            'type' => 'separator',
            'margin' => 'md'
          ],
          [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'contents' => [
              ['type' => 'text', 'text' => '總金額: $' . $bill['total_amount']],
              ['type' => 'text', 'text' => '付款人: ' . $payerName],
              ['type' => 'text', 'text' => '參與者: ' . $participantsList]
            ]
          ]
        ]
      ]
    ];

    return [
      'type' => 'flex',
      'altText' => $altText,
      'contents' => $flexMessage
    ];
  }
  // 結算
  public static function getFinalBalance($db, $groupId)
  {
    $balances = []; // 儲存每個人應收或應付的金額

    $sql = "SELECT b.bill_id, b.total_amount, b.payer_user_id, p.user_id AS participant_user_id
                FROM bills b
                JOIN bill_participants p ON b.bill_id = p.bill_id
                WHERE b.group_id = ? AND b.is_settled = FALSE";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    //遍歷每一筆紀錄
    $processedBills = [];
    while ($row = $result->fetch_assoc()) {
      $billId = $row['bill_id'];
      $totalAmount = (float)$row['total_amount']; //單筆總金額
      $payerId = $row['payer_user_id']; //付款人
      $participantId = $row['participant_user_id']; //參與者

      // 確保每個使用者的餘額都已初始化為 0
      if (!isset($balances[$payerId])) {
        $balances[$payerId] = 0.0;
      }
      if (!isset($balances[$participantId])) {
        $balances[$participantId] = 0.0;
      }

      if (!isset($processedBills[$billId])) {
        // 計算每人應付金額
        $numParticipants = self::countParticipants($db, $billId);
        $owedPerPerson = $totalAmount / $numParticipants;

        // 付款人的餘額增加總金額
        $balances[$payerId] += $totalAmount;

        // 儲存這筆帳單的計算結果，避免重複計算
        $processedBills[$billId] = ['owedPerPerson' => $owedPerPerson];
      }

      // 參與者的餘額扣除應付金額
      $owedPerPerson = $processedBills[$billId]['owedPerPerson'];
      $balances[$participantId] -= $owedPerPerson;
    }
    $stmt->close();

    return $balances;
  }
  //計算參與者人數
  public static function countParticipants($db, $billId)
  {
    $sql = "SELECT COUNT(*) AS count FROM bill_participants WHERE bill_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $billId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      return (int)$row['count'];
    }

    return 0;
  }

  public static function calculateSettlementTransactions(array $balances)
  {
    $debtors = [];
    $creditors = [];

    // 1. 將用戶分為債務人（應付）和債權人（應收）
    foreach ($balances as $userId => $balance) {
      if ($balance < -0.01) { // 使用小數誤差範圍以處理浮點數
        $debtors[$userId] = $balance;
      } elseif ($balance > 0.01) {
        $creditors[$userId] = $balance;
      }
    }

    // 排序以獲得一致的交易建議（非必要，但有助於測試和可預測性）
    arsort($creditors); // 應收金額多的人優先
    asort($debtors);   // 應付金額多的人優先

    $transactions = [];

    // 2. 進行結算匹配
    while (!empty($debtors) && !empty($creditors)) {
      $debtorId = key($debtors);
      $debtorAmount = $debtors[$debtorId];

      $creditorId = key($creditors);
      $creditorAmount = $creditors[$creditorId];

      $transferAmount = min(abs($debtorAmount), $creditorAmount);

      $transactions[] = [
        'from' => $debtorId,
        'to' => $creditorId,
        'amount' => $transferAmount
      ];

      // 更新餘額
      $debtors[$debtorId] += $transferAmount;
      $creditors[$creditorId] -= $transferAmount;

      // 移除已結清的用戶
      if (abs($debtors[$debtorId]) < 0.01) unset($debtors[$debtorId]);
      if (abs($creditors[$creditorId]) < 0.01) unset($creditors[$creditorId]);
    }

    return $transactions;
  }
  //createBalanceReportFlexMessage
  public static function createBalanceReportFlexMessage($balances, $transactions = [])
  {
    $balanceContents = [];
    $hasBalance = false;
    foreach ($balances as $userId => $balance) {
      // 忽略餘額為零的使用者
      if (abs($balance) > 0.01) {
        $hasBalance = true;
        $profile = MessageHandler::getProfile($userId);
        $userName = $profile['displayName'] ?? '未知用戶';
        $color = $balance > 0 ? '#1DB446' : '#EF4444';
        $sign = $balance > 0 ? '應收' : '應付';
        $amount = abs($balance);

        $balanceContents[] = [
          'type' => 'box',
          'layout' => 'horizontal',
          'contents' => [
            ['type' => 'text', 'text' => $userName, 'flex' => 2],
            ['type' => 'text', 'text' => $sign, 'align' => 'end', 'flex' => 1],
            ['type' => 'text', 'text' => '$' . number_format($amount, 2), 'align' => 'end', 'color' => $color, 'weight' => 'bold', 'flex' => 2]
          ]
        ];
        $balanceContents[] = ['type' => 'separator', 'margin' => 'sm'];
      }
    }

    if ($hasBalance) {
      array_pop($balanceContents);
    } else {
      $balanceContents[] = ['type' => 'text', 'text' => '目前沒有待結算的帳單。', 'align' => 'center'];
    }

    // 建立轉帳建議區塊
    $transactionContents = [];
    if (!empty($transactions)) {
      $transactionContents[] = ['type' => 'separator', 'margin' => 'xl'];
      $transactionContents[] = [
        'type' => 'text',
        'text' => '💡 轉帳建議',
        'weight' => 'bold',
        'size' => 'lg',
        'margin' => 'lg',
        'color' => '#555555'
      ];

      $userIds = [];
      foreach ($transactions as $t) {
        $userIds[$t['from']] = true;
        $userIds[$t['to']] = true;
      }
      $profiles = self::getProfilesForUserIds(array_keys($userIds));

      foreach ($transactions as $transaction) {
        $fromName = $profiles[$transaction['from']]['displayName'] ?? '未知用戶';
        $toName = $profiles[$transaction['to']]['displayName'] ?? '未知用戶';
        $amount = number_format($transaction['amount'], 2);

        $transactionContents[] = [
          'type' => 'box',
          'layout' => 'horizontal',
          'margin' => 'md',
          'contents' => [
            ['type' => 'text', 'text' => $fromName, 'gravity' => 'center', 'flex' => 3, 'wrap' => true, 'size' => 'sm'],
            ['type' => 'text', 'text' => '→', 'gravity' => 'center', 'flex' => 1, 'align' => 'center', 'color' => '#aaaaaa'],
            ['type' => 'text', 'text' => $toName, 'gravity' => 'center', 'flex' => 3, 'wrap' => true, 'size' => 'sm'],
            ['type' => 'text', 'text' => '$' . $amount, 'gravity' => 'center', 'flex' => 3, 'align' => 'end', 'weight' => 'bold', 'color' => '#111111']
          ]
        ];
      }
    }

    $buttons = [];
    if ($hasBalance) {
      $buttons[] = [
        'type' => 'button',
        'style' => 'link',
        'height' => 'sm',
        'action' => [
          'type' => 'postback',
          'label' => '✔️ 確認結算並清除紀錄',
          'data' => 'settle_up',
        ]
      ];
    }

    $flexMessage = [
      'type' => 'bubble',
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => array_merge(
          [
            ['type' => 'text', 'text' => '💰 結算報告', 'weight' => 'bold', 'size' => 'xl'],
            ['type' => 'separator', 'margin' => 'md']
          ],
          $balanceContents,
          $transactionContents
        )
      ],
      'footer' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => $buttons
      ]
    ];

    return [
      'type' => 'flex',
      'altText' => '結算報告',
      'contents' => $flexMessage
    ];
  }
  // 修改帳單結算後狀態
  public static function settleBills($db, $groupId)
  {
    $sql = "UPDATE bills SET is_settled = TRUE WHERE group_id = ? AND is_settled = FALSE";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $groupId);
    $stmt->execute();

    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    return $affected_rows;
  }
  //獲取群組帳單
  public static function getBillsForGroup($db, $groupId)
  {
    $sql = "SELECT b.bill_id, b.bill_name, b.total_amount, b.payer_user_id, p.user_id AS participant_user_id
            FROM bills b
            LEFT JOIN bill_participants p ON b.bill_id = p.bill_id
            WHERE b.group_id = ? AND b.is_settled = FALSE
            ORDER BY b.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();

    $bills = [];
    $userIds = [];
    while ($row = $result->fetch_assoc()) {
      $billId = $row['bill_id'];
      if (!isset($bills[$billId])) {
        $bills[$billId] = [
          'bill_id' => $billId,
          'bill_name' => $row['bill_name'],
          'total_amount' => $row['total_amount'],
          'payer_user_id' => $row['payer_user_id'],
          'participants_user_ids' => []
        ];
        $userIds[$row['payer_user_id']] = true;
      }
      if ($row['participant_user_id']) {
        $bills[$billId]['participants_user_ids'][] = $row['participant_user_id'];
        $userIds[$row['participant_user_id']] = true;
      }
    }
    $stmt->close();

    $profiles = self::getProfilesForUserIds(array_keys($userIds));

    $response = [];
    foreach ($bills as $bill) {
      $participantNames = [];
      foreach ($bill['participants_user_ids'] as $pId) {
        $participantNames[] = $profiles[$pId]['displayName'] ?? '未知用戶';
      }

      $response[] = [
        'bill_id' => $bill['bill_id'],
        'bill_name' => $bill['bill_name'],
        'total_amount' => $bill['total_amount'],
        'payer_name' => $profiles[$bill['payer_user_id']]['displayName'] ?? '未知用戶',
        'participants_names' => $participantNames
      ];
    }

    return $response;
  }
  // 獲取單一帳單詳細資訊
  public static function getBillDetails($db, $billId)
  {
    $bill = Bill::getBillById($db, $billId);
    if (!$bill) {
      return null;
    }

    $participants = Participant::getParticipantsByBillId($db, $billId);
    $participantUserIds = array_map(function ($p) {
      return $p['user_id'];
    }, $participants);

    return [
      'bill_id' => (int)$bill['bill_id'],
      'bill_name' => $bill['bill_name'],
      'total_amount' => (float)$bill['total_amount'],
      'payer_user_id' => $bill['payer_user_id'],
      'participants_user_ids' => $participantUserIds
    ];
  }
  // 獲取用戶名稱
  public static function getProfilesForUserIds(array $userIds)
  {
    $profiles = [];
    foreach ($userIds as $userId) {
      $profile = MessageHandler::getProfile($userId);
      if ($profile) {
        $profiles[$userId] = $profile;
      }
    }
    return $profiles;
  }
}
