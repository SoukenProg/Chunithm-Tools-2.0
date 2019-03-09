<?php

header("Access-Control-Allow-Origin: *");
if (isset($_POST["token"], $_POST["data"])) {
    $settingJSON = file_get_contents("../config.json");
    if ($settingJSON === FALSE) {
        echo "サーバー内部エラーです。";
        return;
    }

    $settings = json_decode($settingJSON, TRUE);
    $dbName = $settings["DBName"];
    $dbUserName = $settings["DBUserName"];
    $dbPassword = $settings["DBPassword"];
    $dbHost = $settings["DBHost"];
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUserName, $dbPassword);

    $token = $_POST["token"];
    $playerData = json_decode($_POST["data"], TRUE);

    $statement = $pdo->prepare("SELECT PLAYERID FROM BOOKMARKLETTOKEN WHERE TOKEN = ?");
    $statement->execute([$token]);

    if ($statement->rowCount() > 0) {
        $row = $statement->fetch();
        $statement = $pdo->prepare("INSERT INTO PLAYER VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE PLAYERNAME = ?, MAXRATE = ?, CURRENTRATE = ?, TITLE = ?, EMBLEMTOP = ?, EMBLEMBASE = ?");
        $statement->execute([
            $row["PLAYERID"],
            $playerData["name"],
            $playerData["maxRate"],
            $playerData["currentRate"],
            $playerData["title"],
            $playerData["emblemTop"],
            $playerData["emblemBase"],
            $playerData["name"],
            $playerData["maxRate"],
            $playerData["currentRate"],
            $playerData["title"],
            $playerData["emblemTop"],
            $playerData["emblemBase"]
        ]);
        echo "SUCCESS";
    } else {
        echo "ブックマークレットが無効です。";
    }
} else {
    echo "ブックマークレットが無効です。";
}