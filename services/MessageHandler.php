<?php

require_once __DIR__ . '/../models/DbConnection.php';
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../services/BillService.php';
require_once __DIR__ . '/../config/linebot.php';

class MessageHandler
{
  // 處理文字訊息
  public static function handleText($userMessage, $groupId)
  {
    $db = DbConnection::getInstance();
    $whitelist_enabled_row = $db->query("SELECT setting_value FROM bot_settings WHERE setting_key = 'whitelist_enabled'")->fetch_assoc();
    if ($whitelist_enabled_row && $whitelist_enabled_row['setting_value'] === 'true') {
      $stmt = $db->prepare("SELECT status FROM group_whitelist WHERE group_id = ?");
      $stmt->bind_param("s", $groupId);
      $stmt->execute();
      $group_row = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$group_row || $group_row['status'] !== 'approved') {
        return [
          'type' => 'text',
          'text' => '本群組尚未通過審核，無法使用此功能。'
        ];
      }
    }
    $fullLiffUrl_add = 'https://liff.line.me/2008005425-w5zrAGqk' . '?groupId=' . urlencode($groupId);
    $fullLiffUrl_get = 'https://liff.line.me/2008005425-9w3Ydy41' . '?groupId=' . urlencode($groupId);
    if ($userMessage === '/分帳') {
      $flexMessageJson = '{
      "type": "bubble",
      "size": "deca",
      "header": {
        "type": "box",
        "layout": "vertical",
        "contents": [
          {
            "type": "text",
            "text": "分帳小幫手",
            "weight": "bold",
            "size": "xxl",
            "align": "center",
            "color": "#333333"
          }
        ],
        "justifyContent": "center",
        "alignItems": "center"
      },
      "body": {
        "type": "box",
        "layout": "vertical",
        "contents": [
          {
            "type": "text",
            "text": "「 點擊加入，開始分帳 」",
            "align": "center",
            "color": "#888888",
            "size": "md",
            "weight": "bold",
            "offsetTop": "8px"
          },
          {
            "type": "box",
            "layout": "vertical",
            "contents": [
              {
                "type": "text",
                "text": "👤 加入名單 ",
                "size": "sm",
                "align": "center",
                "color": "#6fa8dc",
                "weight": "bold"
              }
            ],
            "width": "120px",
            "borderWidth": "medium",
            "borderColor": "#6fa8dc",
            "cornerRadius": "xxl",
            "justifyContent": "center",
            "alignItems": "center",
            "height": "30px",
            "margin": "md",
            "action": {
              "type": "postback",
              "label": "action",
              "data": "register_member"
            }
          },
          {
            "type": "box",
            "layout": "vertical",
            "contents": [
              {
                "type": "box",
                "layout": "vertical",
                "contents": [
                  {
                    "type": "text",
                    "text": "＋ 新增帳單",
                    "color": "#FFFFFF",
                    "weight": "bold",
                    "size": "lg"
                  }
                ],
                "backgroundColor": "#2c3e50",
                "width": "180px",
                "justifyContent": "center",
                "alignItems": "center",
                "cornerRadius": "md",
                "height": "40px",
                "action": {
                  "type": "uri",
                  "label": "action",
                  "uri": "' . $fullLiffUrl_add . '"
                }
              },
              {
                "type": "box",
                "layout": "vertical",
                "contents": [
                  {
                    "type": "text",
                    "text": "🔍查看帳單",
                    "color": "#FFFFFF",
                    "weight": "bold",
                    "size": "lg"
                  }
                ],
                "backgroundColor": "#6fa8dc",
                "width": "180px",
                "justifyContent": "center",
                "alignItems": "center",
                "cornerRadius": "md",
                "height": "40px",
                "action": {
                  "type": "uri",
                  "label": "action",
                  "uri": "' . $fullLiffUrl_get . '"
                }
              },
              {
                "type": "box",
                "layout": "vertical",
                "contents": [
                  {
                    "type": "text",
                    "text": "✅結算",
                    "color": "#FFFFFF",
                    "size": "lg",
                    "weight": "bold"
                  }
                ],
                "backgroundColor": "#00a8a8",
                "width": "180px",
                "height": "40px",
                "cornerRadius": "md",
                "justifyContent": "center",
                "alignItems": "center",
                "action": {
                  "type": "postback",
                  "label": "action",
                  "data": "get_balance"
                }
              }
            ],
            "height": "160px",
            "justifyContent": "flex-end",
            "spacing": "lg"
          }
        ],
        "alignItems": "center",
        "justifyContent": "center",
        "spacing": "md",
        "backgroundColor": "#eff2f6"
      }
    }';

      $flexMessageArray = json_decode($flexMessageJson, true);

      return [
        'type' => 'flex',
        'altText' => '分帳小幫手選單',
        'contents' => $flexMessageArray
      ];
    } else {
      return null;
    }
  }
  // 處理postback
  public static function handlePostback($data, $groupId, $userId)
  {
    parse_str($data, $postbackParams);
    $action = $postbackParams['action'] ?? $data;

    switch ($action) {
      case 'register_member':
        try {
          $db = DbConnection::getInstance();
          $result = Member::registerMember($db, $groupId, $userId);
          $profile = self::getProfile($userId); // 取得使用者資訊
          $displayName = $profile ? $profile['displayName'] : '使用者';
          return [
            'type' => 'text',
            'text' => $result ? $displayName . " 加入成功！" : $displayName . " 已經加入過了。"
          ];
        } catch (Exception $e) {
          error_log('Error in register_member postback: ' . $e->getMessage());
          return [
            'type' => 'text',
            'text' => '請在群組內使用。'
          ];
        }

      case 'get_balance':
        try {
          $db = DbConnection::getInstance();
          $balances = BillService::getFinalBalance($db, $groupId);

          // 如果沒有任何待結算餘額，直接回傳訊息
          if (empty($balances)) {
            return ['type' => 'text', 'text' => '目前沒有任何帳單可以結算。'];
          }

          $transactions = BillService::calculateSettlementTransactions($balances);
          $reportMessage = BillService::createBalanceReportFlexMessage($balances, $transactions);
          return $reportMessage;
        } catch (Exception $e) {
          error_log('Error in get_balance postback: ' . $e->getMessage());
          return [
            'type' => 'text',
            'text' => '結算時發生錯誤，請稍後再試或聯絡管理員。'
          ];
        }

      case 'settle_up':
        $db = DbConnection::getInstance();
        BillService::settleBills($db, $groupId);

        return [
          'type' => 'text',
          'text' => '帳單已成功結算！'
        ];

      case 'approve_group':
        $targetGroupId = $postbackParams['groupId'];
        $db = DbConnection::getInstance();
        $stmt = $db->prepare("UPDATE group_whitelist SET status = 'approved' WHERE group_id = ?");
        $stmt->bind_param("s", $targetGroupId);
        $stmt->execute();

        self::sendPushMessage($targetGroupId, ['type' => 'text', 'text' => '恭喜！本群組已通過審核，您現在可以開始使用所有功能了。輸入 /分帳 來查看選單。']);
        return ['type' => 'text', 'text' => "群組 {$targetGroupId} 已核准。"];

      case 'deny_group':
        $targetGroupId = $postbackParams['groupId'];
        $db = DbConnection::getInstance();
        $stmt = $db->prepare("UPDATE group_whitelist SET status = 'denied' WHERE group_id = ?");
        $stmt->bind_param("s", $targetGroupId);
        $stmt->execute();

        self::sendPushMessage($targetGroupId, ['type' => 'text', 'text' => '很抱歉，您的群組未通過審核，機器人即將離開。']);
        self::leaveGroup($targetGroupId);
        return ['type' => 'text', 'text' => "群組 {$targetGroupId} 已拒絕並移除。"];

      default:
        return [
          'type' => 'text',
          'text' => '無法識別的指令。'
        ];
    }
  }
  // 取得使用者
  public static function getProfile($userId)
  {
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN') || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
      error_log('LINE_CHANNEL_ACCESS_TOKEN is not defined or empty.');
      return null;
    }

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($userId);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
      error_log('cURL error when getting profile for ' . $userId . ': ' . curl_error($ch));
      curl_close($ch);
      return null;
    }

    curl_close($ch);

    $profile = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($profile['displayName'])) {
      error_log('Failed to decode profile JSON or missing displayName for userId: ' . $userId);
      return null;
    }

    return $profile;
  }
  // 新增帳單後，傳送明細到群組
  public static function sendPushMessage($groupId, $flexMessage)
  {
    require_once __DIR__ . '/../config/linebot.php';

    $replyData = [
      'to' => $groupId,
      'messages' => [$flexMessage]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_exec($ch);
    curl_close($ch);
  }
  // 讓機器人離開群組
  public static function leaveGroup($groupId)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.line.me/v2/bot/group/' . $groupId . '/leave');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_exec($ch);
    curl_close($ch);
  }
  // 處理機器人被加入群組事件
  public static function handleJoinEvent($groupId)
  {
    $db = DbConnection::getInstance();
    $whitelist_enabled_row = $db->query("SELECT setting_value FROM bot_settings WHERE setting_key = 'whitelist_enabled'")->fetch_assoc();

    if (!$whitelist_enabled_row || $whitelist_enabled_row['setting_value'] !== 'true') {
      self::sendPushMessage($groupId, ['type' => 'text', 'text' => '感謝邀請！輸入 /分帳 來查看功能選單。']);
      return;
    }

    // Whitelist is enabled, start the approval process
    $stmt = $db->prepare("INSERT INTO group_whitelist (group_id, status) VALUES (?, 'pending') ON DUPLICATE KEY UPDATE status = 'pending'");
    $stmt->bind_param("s", $groupId);
    $stmt->execute();
    $stmt->close();

    // Notify the group
    self::sendPushMessage($groupId, ['type' => 'text', 'text' => '感謝您的邀請！本群組已提交審核，管理員將盡快處理。']);

    // Notify the admin
    $adminMessage = [
      'type' => 'flex',
      'altText' => '新群組審核請求',
      'contents' => [
        'type' => 'bubble',
        'header' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [['type' => 'text', 'text' => '新群組審核請求', 'weight' => 'bold', 'size' => 'lg']]],
        'body' => [
          'type' => 'box',
          'layout' => 'vertical',
          'spacing' => 'md',
          'contents' => [
            ['type' => 'text', 'text' => '一個新的群組正在等待您的審核。', 'wrap' => true],
            ['type' => 'separator'],
            ['type' => 'box', 'layout' => 'baseline', 'contents' => [
              ['type' => 'text', 'text' => '群組ID', 'flex' => 2, 'color' => '#aaaaaa'],
              ['type' => 'text', 'text' => $groupId, 'flex' => 5, 'wrap' => true]
            ]]
          ]
        ],
        'footer' => [
          'type' => 'box',
          'layout' => 'horizontal',
          'spacing' => 'sm',
          'contents' => [
            ['type' => 'button', 'style' => 'primary', 'color' => '#27ae60', 'action' => [
              'type' => 'postback',
              'label' => '核准',
              'data' => 'action=approve_group&groupId=' . $groupId
            ]],
            ['type' => 'button', 'style' => 'primary', 'color' => '#c0392b', 'action' => [
              'type' => 'postback',
              'label' => '拒絕',
              'data' => 'action=deny_group&groupId=' . $groupId
            ]]
          ]
        ]
      ]
    ];

    if (defined('LINE_ADMIN_USER_ID') && !empty(LINE_ADMIN_USER_ID)) {
      self::sendPushMessage(LINE_ADMIN_USER_ID, $adminMessage);
    }
  }
}
