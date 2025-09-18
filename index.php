<?php
require_once __DIR__ . '/config/linebot.php';
require_once __DIR__ . '/services/MessageHandler.php';
require_once __DIR__ . '/services/LineBotService.php';

$httpRequestBody = file_get_contents('php://input');
$hash = base64_encode(hash_hmac('sha256', $httpRequestBody, LINE_CHANNEL_SECRET, true));

if ($hash !== $_SERVER['HTTP_X_LINE_SIGNATURE']) {

  error_log('LINE Signature validation failed!');
  http_response_code(400);
  exit();
}

$request = json_decode($httpRequestBody, true);

if (empty($request['events'])) {
  http_response_code(200);
  exit();
}


$bot = new LineBotService(LINE_CHANNEL_ACCESS_TOKEN);

foreach ($request['events'] as $event) {
  $replyMessage = null;
  $replyToken = $event['replyToken'];
  $groupId = $event['source']['groupId'] ?? null;
  $userId = $event['source']['userId'] ?? null;

  if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
    $userMessage = trim($event['message']['text']);
    $replyMessage = MessageHandler::handleText($userMessage,$groupId);
  } else if ($event['type'] == 'postback') {
    $postbackData = $event['postback']['data'];
    $replyMessage = MessageHandler::handlePostback($postbackData, $groupId, $userId);
  } else if ($event['type'] == 'join') {
    MessageHandler::handleJoinEvent($groupId);
    // Join events don't have a reply token.
  }

  if (!empty($replyMessage) && (isset($replyMessage['type']))) {
    $bot->replyMessage($replyToken, [$replyMessage]);
  }
}

http_response_code(200);
echo 'OK';
