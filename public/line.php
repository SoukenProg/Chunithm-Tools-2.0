<?php

require("./main.php");

$config = new Config();
if ($config->isConfigLoaded() === FALSE) {
    exit;
}

$settings = $config->getSettings();

$xLineSignature = $_SERVER["HTTP_X_LINE_SIGNATURE"];
$secret = $settings["Secret"];
$accessToken = $settings["AccessToken"];
$requestBody = file_get_contents("php://input");
$requestJson = json_decode($requestBody, true);

$signature = base64_encode(hash_hmac("sha256", $requestBody, $secret, true));

if ($signature !== $xLineSignature) {
    exit;
}

$events = $requestJson["events"];
foreach ($events as $message) {
    $eventType = $message["type"];
    $result = "";
    if ($eventType === "join") {
        $result = "グループへの招待ありがとうございます。helpまたはmanと送信することで使い方がわかります。";
    } elseif ($eventType !== "message") {
        continue;
    } else {
        $messageType = $message["message"]["type"];
        if ($messageType !== "text") {
            continue;
        }
        
        $client = new CommandLine();

        if (isset($message["source"]["userId"])) {
            $lineUserId = $message["source"]["userId"];
            $playerId = hash_hmac("sha256", $lineUserId, $secret);
            $client->signIn($playerId);
        }
        if (isset($message["source"]["groupId"])) {
            $lineGroupId = $message["source"]["groupId"];
            $groupId = hash_hmac("sha256", $lineGroupId, $secret);
            $client->setGroup($groupId);
        } elseif (isset($message["source"]["roomId"])) {
            $lineGroupId = $message["source"]["roomId"];
            $groupId = hash_hmac("sha256", $lineGroupId, $secret);
            $client->setGroup($groupId);
        }
    
        $text = strtolower($message["message"]["text"]);
        $result = trim($client->evalCommand($text));
    }

    $replyToken = $message["replyToken"];
    if ($result !== "") {
        $postData = [
            "replyToken" => $replyToken,
            "messages" => [[
                "type" => "text",
                "text" => $result
            ]]
        ];
        $postJson = json_encode($postData);

        $ch = curl_init("https://api.line.me/v2/bot/message/reply");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json; charser=UTF-8",
            "Authorization: Bearer {$accessToken}"
        ]);
        curl_exec($ch);
        curl_close($ch); 
    }
}