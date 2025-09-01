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
    $liffBaseUrl = 'https://liff.line.me/2008005425-w5zrAGqk';
    $fullLiffUrl = $liffBaseUrl . '?groupId=' . urlencode($groupId);
    if ($userMessage === '功能') {
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
                "text": "新增帳單",
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
              "uri": "' . $fullLiffUrl . '"
            }
          },
          {
            "type": "box",
            "layout": "vertical",
            "contents": [
              {
                "type": "text",
                "text": "結算",
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
        "height": "120px",
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
      return [
        'type' => 'text',
        'text' => '你好！我是分帳小幫手。請輸入「功能」來查看選單。'
      ];
    }
  }
  // 處理postback
  public static function handlePostback($data, $groupId, $userId)
  {
    switch ($data) {
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
          $report = BillService::getFinalBalance($db, $groupId);

          // 檢查是否有帳務資料可供結算
          // if (empty($report['balances']) && empty($report['transactions'])) {
          //   return [
          //     'type' => 'text',
          //     'text' => '目前沒有任何帳單可以結算。'
          //   ];
          // }

          // 產生結算報告 Flex Message
          $reportMessage = BillService::createBalanceReportFlexMessage($report);
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
}
