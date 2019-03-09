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
    $scores = json_decode($_POST["data"], TRUE);

    $statement = $pdo->prepare("SELECT PLAYERID FROM BOOKMARKLETTOKEN WHERE TOKEN = ?");
    $statement->execute([$token]);

    if ($statement->rowCount() > 0) {
        $row = $statement->fetch();

        $pdo->beginTransaction();
        foreach ($scores as $songId => $singleScore) {
            $statement = $pdo->prepare("INSERT INTO SCORE VALUES(?,?,?,0,?,0,?,0,?,0) ON DUPLICATE KEY UPDATE MASTERSCORE = ?, EXPERTSCORE = ?, ADVANCEDSCORE = ?, BASICSCORE = ?");
            $statement->execute([
                $row["PLAYERID"],
                $songId,
                $singleScore[3],
                $singleScore[2],
                $singleScore[1],
                $singleScore[0],
                $singleScore[3],
                $singleScore[2],
                $singleScore[1],
                $singleScore[0]
            ]);
        }
        $pdo->commit();

        echo "SUCCESS";
    } else {
        echo "ブックマークレットが無効です。";
    }
} else {
    echo "ブックマークレットが無効です。";
}