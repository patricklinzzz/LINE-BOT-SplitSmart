<?php
require_once __DIR__ . '/config/linebot.php';
require_once __DIR__ . '/services/MessageHandler.php';

$httpRequestBody = file_get_contents('php://input');

if (base64_encode(hash_hmac('sha256', $httpRequestBody, LINE_CHANNEL_SECRET, true)) !== $_SERVER['HTTP_X_LINE_SIGNATURE']) {
  http_response_code(400);
  exit();
}

$request = json_decode($httpRequestBody, true);

if (!empty($request['events'])) {
  foreach ($request['events'] as $event) {
    $replyMessage = null;
    $replyToken = $event['replyToken'];
    $groupId = $event['source']['groupId'] ?? '';
    $userId = $event['source']['userId'] ?? '';

    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
      $userMessage = trim($event['message']['text']);
      $replyMessage = MessageHandler::handleText($userMessage);
    } else if ($event['type'] == 'postback') {
      $postbackData = $event['postback']['data'];
      $replyMessage = MessageHandler::handlePostback($postbackData, $groupId, $userId);
    }

    if ($replyMessage) {
      $replyData = [
        'replyToken' => $replyToken,
        'messages' => [$replyMessage]
      ];

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, 'https://api.line.me/v2/bot/message/reply');
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyData));
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
      ));
      $result = curl_exec($ch);
      curl_close($ch);
    }
    break;
  }
}
