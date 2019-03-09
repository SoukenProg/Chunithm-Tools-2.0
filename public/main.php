<?php

declare(strict_types=1);

require("./functions.php");

define("TOO_MANY_PARAMETERS", "Error: パラメータが多すぎます。");
define("TOO_FEW_PARAMETERS", "Error: パラメータが少なすぎます。");

class PDOController {
    private $pdo;

    public function __construct(string $dbName, string $dbUserName, string $dbPassword) {
        $this->pdo = new PDO("mysql:host=mysql7065.xserver.jp;dbname={$dbName}", $dbUserName, $dbPassword);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function query(string $query, array $parameters): PDOStatement {
        $statement = $this->pdo->prepare($query);
        $statement->execute($parameters);
        return $statement;
    }
}

class Config {
    private $settings;

    public function __construct() {
        $settingJSON = file_get_contents("../config.json");
        if ($settingJSON === FALSE) {
            return;
        }

        $this->settings = json_decode($settingJSON, TRUE);
        return;
    }

    public function isConfigLoaded(): bool {
        if (isset($this->settings)) {
            return TRUE;
        }
        return FALSE;
    }

    public function getSettings(): array {
        return $this->settings;
    }
}

class ChunithmTools {
    private $playerId;
    private $groupId;
    private $pdoController;
    private $isScoreRegistered;

    public function __construct() {
        $config = new Config();
        if ($config->isConfigLoaded() === FALSE) {
            $this->outputLog("chunithmtools_log.txt", "config.jsonが存在しません。");
            return;
        }

        $settings = $config->getSettings();
        $this->pdoController = new PDOController($settings["DBName"], $settings["DBUserName"], $settings["DBPassword"]);
        $this->isScoreRegistered = FALSE;
        $this->playerId = null;
        $this->groupId = null;
    }

    private function searchSongs(string $query): array {
        if ($query !== "" && $query[0] === "/") {
            $songId = (int)substr($query, 1);
            $statement = $this->pdoController->query("SELECT COUNT(SONGID) AS CNT FROM SONG WHERE SONGID = ?", [$songId]);
            $row = $st -> fetch();
            if ((int)$row["CNT"] > 0) {
                return [$songId];
            } else {
                return [];
            }
        }
        $statement = $this->pdoController->query("SELECT SONGID FROM ALIAS WHERE ALIAS = ?", [$query]);
        if ($row = $statement->fetch()) {
            return [(int)$row["SONGID"]];
        } else {
            $statement = $this->pdoController->query("SELECT SONGID FROM SONG WHERE SONGNAME LIKE ?", ["{$query}%"]);
            $songs = [];
            while ($row = $statement->fetch()) {
                $songs[] = (int)$row["SONGID"];
            }
            return $songs;
        }
    }

    private function getSingleSong(int $songId): array {
        $statement = $this->pdoController->query("SELECT SONGNAME, MASTERDIFFICULTY, EXPERTDIFFICULTY, ADVANCEDDIFFICULTY, BASICDIFFICULTY, NOTESCOUNT, SCOREVIDEOURL, SCOREIMAGEURL FROM SONG WHERE SONGID = ?", [$songId]);
        if ($row = $statement->fetch()) {
            return [
                "songName" => $row["SONGNAME"],
                "master" => $row["MASTERDIFFICULTY"],
                "expert" => $row["EXPERTDIFFICULTY"],
                "advanced" => $row["ADVANCEDDIFFICULTY"],
                "basic" => $row["BASICDIFFICULTY"],
                "notes" => $row["NOTESCOUNT"],
                "scoreVideo" => $row["SCOREVIDEOURL"],
                "scoreImage" => $row["SCOREIMAGEURL"]
            ];
        }
        return [];
    }

    private function getSingleScore(int $songId): array {
        $statement = $this->pdoController->query("SELECT MASTERSCORE, MASTERMARK, EXPERTSCORE, EXPERTMARK, ADVANCEDSCORE, ADVANCEDMARK, BASICSCORE, BASICMARK FROM SCORE WHERE PLAYERID = ? AND SONGID = ?", [$this->playerId, $songId]);
        if ($row = $statement->fetch()) {
            return [
                "masterScore" => $row["MASTERSCORE"],
                "masterMark" => $row["MASTERMARK"],
                "expertScore" => $row["EXPERTSCORE"],
                "expertMark" => $row["EXPERTMARK"],
                "advancedScore" => $row["ADVANCEDSCORE"],
                "advancedMark" => $row["ADVANCEDMARK"],
                "basicScore" => $row["BASICSCORE"],
                "basicMark" => $row["BASICMARK"],
            ];
        }
        return [];
    }

    private function showSuggestion(array $songIds): string {
        $resultString = "曲が絞り切れません。\n以下の候補から一曲選び、曲名の真下の文字列を曲名の代わりに入力してください。";
        foreach ($songIds as $singleSongId) {
            $song = $this->getSingleSong($singleSongId);
            $songName = $song["songName"];
            $resultString .= "\n\n{$songName}\n/{$singleSongId}";
        }
        return $resultString;
    }

    private function getAbsolutePositionById(int $songId, int $score): array {
        $statement = $this->pdoController->query("SELECT COUNT(MASTERSCORE) AS CNT FROM SCORE WHERE MASTERSCORE > 0 AND MASTERSCORE <= 1010000 AND SONGID = ?", [$songId]);
        $row = $statement->fetch();
        $count = (int)$row["CNT"];

        $statement = $this->pdoController->query("SELECT COUNT(MASTERSCORE) AS CNT FROM SCORE WHERE MASTERSCORE > ? AND MASTERSCORE <= 1010000 AND SONGID = ?", [$score, $songId]);
        $row = $statement->fetch();
        $position = (int)$row["CNT"] + 1;

        return [
            "count" => $count,
            "position" => $position
        ];
    }

    private function getSongDifficultyById(int $songId, int $score): int {
        $rank = $this->getAbsolutePositionById($songId, $score);
        $rate = $rank["position"] / $rank["count"];
        $statement = $this->pdoController->query("SELECT COUNT(MAXRATE) AS CNT FROM PLAYER WHERE MAXRATE > 1300", []);
        $row = $statement->fetch();
        $position = (string)((int)($rate * (int)$row["CNT"]));
        $statement = $this->pdoController->query("SELECT MAXRATE FROM PLAYER WHERE MAXRATE > 1300 ORDER BY MAXRATE DESC LIMIT 1 OFFSET " . $position, []);
        if ($statement->rowCount() > 0) {
            $row = $statement->fetch();
            return (int)$row["MAXRATE"];
        } else {
            return 1298;
        }
    }

    public function signIn(string $playerId): void {
        $this->playerId = $playerId;
        $statement = $this->pdoController->query("SELECT COUNT(PLAYERID) AS CNT FROM PLAYER WHERE PLAYERID = ?", [$playerId]);
        $row = $statement->fetch();
        if ((int)$row["CNT"] > 0) {
            $this->isScoreRegistered = TRUE;
        }
    }

    public function setGroup(string $groupId): bool {
        $statement = $this->pdoController->query("SELECT COUNT(GROUPID) AS CNT FROM PLAYERGROUP WHERE GROUPID = ?", [$groupId]);
        $row = $statement->fetch();
        if ((int)$row["CNT"] > 0) {
            $this->groupId = $groupId;
            return FALSE;
        } else {
            $statement = $this->pdoController->query("INSERT INTO PLAYERGROUP VALUES(?,?,?)", [$groupId, "", ""]);
            $this->groupId = $groupId;
            return TRUE;
        }
    }

    public function outputLog(string $filename, string $content): void {
        file_put_contents($filename, $content . "\n", FILE_APPEND | LOCK_EX);
    }

    public function border(string $query, int $score): string {
        $resultString = "";
        if (ctype_digit($query)) {
            $notes = (int)$query;
            $songName = "{$notes}notes";
        } else {
            $songIds = $this->searchSongs($query);
            $songsCount = count($songIds);
            if ($songsCount === 0) {
                return "曲が見つかりません。";
            } elseif ($songsCount > 5) {
                return "曲が絞り切れません。\nより具体的な名前をお試しください。";
            } elseif ($songsCount > 1) {
                return $this->showSuggestion($songIds);
            }
        }

        if (!isset($notes)){
            $song = $this->getSingleSong($songIds[0]);
            $notes = $song["notes"];
            $songName = $song["songName"];
        }

        $resultString = $songName;
        $tolerableJustices = floor((1010000 - $score) / 10000 * $notes);
        $tolerableAttacks = (int)($tolerableJustices / 51) + 1;
        while ($tolerableJustices - $tolerableAttacks * 51 < $tolerableAttacks) {
            $tolerableAttacks--;
        }
        for ($i = $tolerableAttacks; $i >= 0 && $i >= $tolerableAttacks - 10; $i--) {
            $resultString .= "\n" . ($tolerableJustices - $i * 51) . "-" . $i;
        }

        return $resultString;
    }

    public function songInfo(string $query): array {
        $songIds = $this->searchSongs($query);
        $songsCount = count($songIds);
        if ($songsCount === 0) {
            return ["Error" => "曲が見つかりません。"];
        } elseif ($songsCount > 5) {
            return ["Error" => "曲が絞り切れません。\nより具体的な名前をお試しください。"];
        } elseif ($songsCount > 1) {
            return ["Error" => $this->showSuggestion($songIds)];
        }
        return $this->getSingleSong($songIds[0]);
    }

    public function difficulty(string $query, int $score): array {
        $songIds = $this->searchSongs($query);
        $songsCount = count($songIds);
        if ($songsCount === 0) {
            return ["Error" => "曲が見つかりません。"];
        } elseif ($songsCount > 5) {
            return ["Error" => "曲が絞り切れません。\nより具体的な名前をお試しください。"];
        } elseif ($songsCount > 1) {
            return ["Error" => $this->showSuggestion($songIds)];
        }
        $song = $this->getSingleSong($songIds[0]);
        $songName = $song["songName"];
        $difficulty = $this->getSongDifficultyById($songIds[0], $score);
        return [
            "songName" => $songName,
            "difficulty" => $difficulty
        ];
    }

    public function playerInfo(): array {
        if (isset($this->isScoreRegistered)) {
            $statement = $this->pdoController->query("SELECT PLAYERNAME, CURRENTRATE, MAXRATE, TITLE, EMBLEMTOP, EMBLEMBASE FROM PLAYER WHERE PLAYERID = ?", [$playerId]);
            $row = $statement->fetch();
            return [
                "playerName" => $row["PLAYERNAME"],
                "currentRate" => $row["CURRENTRATE"],
                "maxRate" => $row["MAXRATE"],
                "title" => $row["TITLE"],
                "emblemTop" => $row["EMBLEMTOP"],
                "emblemBase" => $row["EMBLEMBASE"]
            ];
        } else {
            return ["Error" => "ログインしてからご利用ください。"];
        }
    }

    public function getScore(string $query) {
        if (!isset($this->isScoreRegistered)) {
            return "Error: ログインしてからご利用ください。";
        }

        $songIds = $this->searchSongs($query);
        $songsCount = count($songIds);
        if ($songsCount === 0) {
            return "Error: 曲が見つかりません。";
        } elseif ($songsCount > 5) {
            return "Error: 曲が絞り切れません。\nより具体的な名前をお試しください。";
        } elseif ($songsCount > 1) {
            return "Error: " . $this->showSuggestion($songIds);
        }
        $song = $this->getSingleSong($songIds[0]);
        $score = $this->getSingleScore($songIds[0]);
        if (empty($score)) {
            return "{$song["songName"]}\nMASTER: 0\nEXPERT: 0\nADVANCED: 0\nBASIC: 0";
        }
        return "{$song["songName"]}\nMASTER: {$score["masterScore"]}\nEXPERT: {$score["expertScore"]}\nADVANCED: {$score["advancedScore"]}\nBASIC: {$score["basicScore"]}";
    }

    public function addRankingMember(): string {
        if (!isset($this->groupId)) {
            return "グループで実行して下さい。";
        }
        if (!isset($this->isScoreRegistered)) {
            return "ログインしてからご利用ください。";
        }

        $playerId = $this->playerId;
        $groupId = $this->groupId;
        $statement = $this->pdoController->query("SELECT COUNT(PLAYERID) AS CNT FROM RANKINGMEMBER WHERE GROUPID = ? AND PLAYERID = ?", [$groupId, $playerId]);
        $row = $statement->fetch();
        if ((int)$row["CNT"] === 0) {
            $statement = $this->pdoController->query("INSERT INTO RANKINGMEMBER VALUES(?,?)", [$groupId, $playerId]);
            return "";
        } else {
            return "既に登録されています。";
        }
    }

    public function removeRankingMember(): string {
        if (!isset($this->group)) {
            return "グループで実行して下さい。";
        }
        if (!isset($this->isScoreRegistered)) {
            return "ログインしてからご利用ください。";
        }

        $playerId = $this->playerId->getPlayerId();
        $groupId = $this->groupId;
        $statement = $this->pdoController->query("SELECT COUNT(PLAYERID) AS CNT FROM RANKINGMEMBER WHERE GROUPID = ? AND PLAYERID = ?", [$groupId, $playerId]);
        $row = $statement->fetch();
        if ((int)$row["CNT"] === 1) {
            $statement = $this->pdoController->query("DELETE FROM RANKINGMEMBER WHERE GROUPID = ? AND PLAYERID = ?", [$groupId, $playerId]);
            return "";
        } else {
            return "このランキングには登録されていません。";
        }
    }

    public function ranking(string $query, int $difficulty = 3): array {
        if (!isset($this->groupId)) {
            return ["Error" => "グループで実行して下さい。"];
        }

        $groupId = $this->groupId;
        if ($query === "total") {
            switch($difficulty) {
                case 0:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SUM(SCORE.BASICSCORE) FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) GROUP BY PLAYER.PLAYERID ORDER BY SCORE.BASICSCORE DESC", [$groupId]);
                    $songName = "TOTAL SCORE(BASIC)";
                    break;
                case 1:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SUM(SCORE.ADVANCEDSCORE) FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) GROUP BY PLAYER.PLAYERID ORDER BY SCORE.ADVANCEDSCORE DESC", [$groupId]);
                    $songName = "TOTAL SCORE(ADVANCED)";
                    break;
                case 2:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SUM(SCORE.EXPERTSCORE) FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) GROUP BY PLAYER.PLAYERID ORDER BY SCORE.EXPERTSCORE DESC", [$groupId]);
                    $songName = "TOTAL SCORE(EXPERT)";
                    break;
                case 3:
                default:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SUM(SCORE.MASTERSCORE) FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) GROUP BY PLAYER.PLAYERID ORDER BY SCORE.MASTERSCORE DESC", [$groupId]);
                    $songName = "TOTAL SCORE(MASTER)";
                    break;
            }
        } else {
            $songIds = $this->searchSongs($query);
            $songsCount = count($songIds);
            if ($songsCount === 0) {
                return ["Error" => "曲が見つかりません。"];
            } elseif ($songsCount > 5) {
                return ["Error" => "曲が絞り切れません。\nより具体的な名前をお試しください。"];
            } elseif ($songsCount > 1) {
                return ["Error" => $this->showSuggestion($songIds)];
            }
            $song = $this->getSingleSong($songIds[0]);
            $songName = $song["songName"];

            switch($difficulty) {
                case 0:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SCORE.BASICSCORE FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND SCORE.SONGID = ? AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) ORDER BY SCORE.BASICSCORE DESC", [$songIds[0], $groupId]);
                    break;
                case 1:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SCORE.ADVANCEDSCORE FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND SCORE.SONGID = ? AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) ORDER BY SCORE.ADVANCEDSCORE DESC", [$songIds[0], $groupId]);
                    break;
                case 2:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SCORE.EXPERTSCORE FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND SCORE.SONGID = ? AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) ORDER BY SCORE.EXPERTSCORE DESC", [$songIds[0], $groupId]);
                    break;
                case 3:
                default:
                    $statement = $this->pdoController->query("SELECT PLAYER.PLAYERNAME, SCORE.MASTERSCORE FROM SCORE INNER JOIN PLAYER ON SCORE.PLAYERID = PLAYER.PLAYERID AND SCORE.SONGID = ? AND PLAYER.PLAYERID IN (SELECT PLAYERID FROM RANKINGMEMBER WHERE GROUPID = ?) ORDER BY SCORE.MASTERSCORE DESC", [$songIds[0], $groupId]);
                    break;
            }
        }

        $result = [
            "songName" => $songName,
            "ranking" => []
        ];
        while ($row = $statement->fetch()) {
            $result["ranking"][] = [
                "playerName" => $row[0],
                "score" => (int)$row[1]
            ];
        }

        return $result;
    }

    public function issueBookmarklet(): string {
        if (!isset($this->playerId)) {
            return "Error: 友達登録してからご利用ください。";
        }
        if (isset($this->groupId)) {
            return "Error: グループでの利用は出来ません。";
        }

        $token = hash("sha256", $this->playerId . microtime());
        $statement = $this->pdoController->query("INSERT INTO BOOKMARKLETTOKEN VALUES(?,?) ON DUPLICATE KEY UPDATE TOKEN = ?", [$this->playerId, $token, $token]);
        return "javascript:s=document.createElement('script');s.src='https://chunithmtools.net/b_{$token}.js';s.setAttribute('crossorigin', 'anonymous');document.body.appendChild(s);";
    }
}

class CommandLine {
    private $chunithmTools;

    public function __construct() {
        $this->chunithmTools = new ChunithmTools();
    }

    private function explodePipes(string $commandString): array {
        $commands = [""];
        $position = 0;
        $quoted = FALSE;
        $nest = 0;
        $length = mb_strlen($commandString);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($commandString, $i, 1);
            if ($char === "@" && !$quoted && $nest === 0) {
                $position++;
                $commands[$position] = "";
            } else {
                if ($char === "\"") {
                    $quoted = !$quoted;
                } elseif ($char === "{" && !$quoted) {
                    $nest++;
                } elseif ($char === "}" && !$quoted) {
                    $nest--;
                }
                $commands[$position] .= $char;
            }
        }

        if ($nest != 0 || $quoted) {
            return ["Error" => "構文に誤りがあります。"];
        }
        return $commands;
    }

    private function explodeParameters($commandString): array {
        $parameters = [""];
        $position = 0;
        $quoted = FALSE;
        $nest = 0;
        $length = mb_strlen($commandString);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($commandString, $i, 1);
            if ($char === "," && !$quoted && $nest === 0) {
                $position++;
                $parameters[$position] = "";
            } elseif ($char === "\"" && $nest === 0) {
                $quoted = !$quoted;
            } else {
                if ($char === "\"") {
                    $quoted = !$quoted;
                } elseif ($char === "{" && !$quoted) {
                    $nest++;
                } elseif ($char === "}" && !$quoted) {
                    $nest--;
                }
                $parameters[$position] .= $char;
            }
        }

        if ($nest != 0 || $quoted) {
            return ["Error" => "構文に誤りがあります。"];
        }
        return $parameters;
    }

    public function signIn(string $playerId): void {
        $this->chunithmTools->signIn($playerId);
    }

    public function setGroup(string $groupId): void {
        $this->chunithmTools->setGroup($groupId);
    }

    public function evalCommand(string $commandString): string {
        $commandString = strtolower(trim($commandString));
        $commandList = $this->explodePipes($commandString);
        if (isset($commandList["Error"])) {
            return "Error: {$commandList["Error"]}";
        }
        $commandsCount = count($commandList);

        $result = FALSE;

        for ($i = $commandsCount - 1; $i >= 0; $i--) {
            $command = trim($commandList[$i]) . " ";
            $spacePosition = strpos($command, " ");
            $newLinePosition = strpos($command, "\n");
            if ($newLinePosition != FALSE && $spacePosition > $newLinePosition) {
                $spacePosition = $newLinePosition;
            }

            $operation = substr($command, 0, $spacePosition);
            $parameters = $this->explodeParameters(substr($command, $spacePosition + 1));
            if (isset($parameters["Error"])) {
                return "Error: {$parameters["Error"]}";
            }
            $parametersCount = count($parameters);

            for ($j = 0; $j < $parametersCount; $j++) {
                $parameters[$j] = trim($parameters[$j]);
            }
            if ($parameters[0] === "" && $parametersCount === 1) {
                $parametersCount = 0;
                $parameters = [];
            }

            if ($result !== FALSE) {
                for ($j = 0; $j < $parametersCount; $j++) {
                    if ($parameters[$j] === "*") {
                        $parameters[$j] = $result;
                        break;
                    }
                }
                if ($j === $parametersCount) {
                    $parameters[$parametersCount] = $result;
                    $parametersCount++;
                }
            }

            if ($operation !== "loop" && $operation !== "if") {
                for ($j = 0; $j < $parametersCount; $j++) {
                    if (substr($parameters[$j], 0, 1) === "{") {
                        $parameters[$j] = $this->evalCommand(substr($parameters[$j], 1, -1));
                    }
                }
            }

            switch($operation) {
                case "help":
                    if ($parametersCount > 2) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount == 0) {
                        $result = "CHUNITHM Tools 2.0\n\nCHUNITHM Toolsの簡易ヘルプです。\n詳しい仕様については、manコマンドをご参照ください。\n\n使用法: help コマンド名\nhelp listでコマンドリスト";
                    } elseif ($parametersCount == 1) {
                        switch($parameters[0]) {
                            case "list":
                                $result = "border\nボーダーを算出します。\n\ninfo\n曲の情報を閲覧します。\n\ndifficulty\n曲の統計的な難易度を表示します。\n\nscore\n譜面確認用URLを表示します。\n\nrank\n曲のスコアランキングを表示します。\n\nmybest\n自己ベストを表示します。\n\nbkl\nブックマークレットを取得します。";
                                break;

                            case "border":
                            case "bd":
                            case "ボーダー":
                                $result = "border (ノーツ数/曲名),(スコア)\n\n指定された曲またはノーツ数で、指定された点数に到達する際に出せる判定数の限界を表示します。\n表示フォーマットは「JUSTICE-ATTACK」です。";
                                break;
                            
                            case "info":
                                $result = "info (曲名)\n\n指定された曲の譜面定数とノーツ数を表示します。";
                                break;

                            case "difficulty":
                            case "dif":
                            case "難易度":
                                $result = "difficulty (曲名),(スコア)\n\n指定された曲で指定されたスコアを獲得する難易度をレート形式で表示します。";
                                break;

                            case "score":
                            case "譜面確認":
                                $result = "score (曲名)\n\n指定された曲の譜面確認動画/画像URLを表示します。";
                                break;

                            case "rank":
                            case "ranking":
                            case "r":
                            case "ランキング":
                                $result = "rank (曲名),(難易度)\n\n指定された難易度の曲のスコアランキングを表示します。曲名にtotalを指定するとトータルハイスコアランキングを表示します。\nランキングの参加者はグループごとに管理され、曲名にjoinを指定することで参加、leaveを指定することで脱退できます。";
                                break;

                            case "mybest":
                            case "sc":
                                $result = "mybest (曲名)\n\n各難易度ごとに、指定された曲の自己ベストを表示します。";
                                break;

                            case "bkl":
                                $result = "bkl\n\nCHUNITHM Toolsにスコアを登録するためのブックマークレットを表示します。\nこのブックマークレットはユーザーごとに固有であるため、必ず秘密にしてください。\n発行するたびにそれ以前のものは無効となります。";
                                break;
                        }
                    }

                    break;

                case "man":
                    $result = "試用版では使用できません。";
                    break;

                case "border":
                case "bd":
                case "ボーダー":
                    if ($parametersCount > 2) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 1) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $score = 1007500;
                        if ($parameters === 2) {
                            $score = Formatter::formatScore($parameters[1]);
                        }

                        $result = $this->chunithmTools->border($parameters[0], $score);
                    }
                    break;

                case "info":
                    if ($parametersCount > 1) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 1) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $song = $this->chunithmTools->songInfo($parameters[0]);
                        if (isset($song["Error"])) {
                            $result = "Error: {$song["Error"]}";
                        } else {
                            $result = "{$song["songName"]}\nMASTER: {$song["masterDifficulty"]}\nEXPERT: {$song["expertDifficulty"]}\nADVANCED: {$song["advancedDifficulty"]}\nBASIC: {$song["basicDifficulty"]}\nNOTES: {$song["notes"]}";
                        }
                    }
                    break;

                case "difficulty":
                case "dif":
                case "難易度":
                    if ($parametersCount > 2) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 2) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $score = Formatter::formatScore($parameters[1]);
                        $song = $this->chunithmTools->difficulty($parameters[0], $score);
                        if (isset($song["Error"])) {
                            $result = "Error: {$song["Error"]}";
                        } else {
                            $difficulty = Formatter::integerToRate($song["difficulty"]);
                            $result = "{$song["songName"]}: {$difficulty}";
                        }
                    }
                    break;

                case "score":
                case "譜面確認":
                    if ($parametersCount > 1) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 1) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $urls = $this->chunithmTools->songInfo($parameters[0]);
                        if (isset($urls["Error"])) {
                            $result = "Error: {$urls["Error"]}";
                        } else {
                            $result = "{$urls["songName"]}:\n{$urls["scoreVideo"]}\n{$urls["scoreImage"]}";
                        }
                    }
                    break;

                case "rank":
                case "ranking":
                case "r":
                case "ランキング":
                    if ($parametersCount > 2) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 1) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $difficulty = 3;
                        $difficultyNames = ["BASIC", "ADVANCED", "EXPERT", "MASTER"];
                        if ($parametersCount == 2) {
                            switch($parameters[1]) {
                                case "basic":
                                case "緑":
                                    $difficulty = 0;
                                    break;

                                case "advanced":
                                case "橙":
                                    $difficulty = 1;
                                    break;

                                case "expert":
                                case "exp":
                                case "赤":
                                    $difficulty = 2;
                                    break;
                            }
                        }
                        if ($parameters[0] == "join") {
                            $ranking = $this->chunithmTools->addRankingMember();
                            if ($ranking == "") {
                                $result = "正常に登録が完了しました。";
                            } else {
                                $result = "Error: {$ranking}";
                            }
                        } elseif ($parameters[0] == "leave") {
                            $ranking = $this->chunithmTools->removeRankingMember();
                            if ($ranking == "") {
                                $result = "正常に登録解除が完了しました。";
                            } else {
                                $result = "Error: {$ranking}";
                            }
                        } else {
                            $ranking = $this->chunithmTools->ranking($parameters[0], $difficulty);
                            if (isset($ranking["Error"])) {
                                $result = "Error: {$ranking["Error"]}";
                            } else {
                                $result = "{$ranking["songName"]}({$difficultyNames[$difficulty]})";

                                $prevScore = -1;
                                $rank = 1;
                                $displayRank = 1;
                                foreach ($ranking["ranking"] as $score) {
                                    if ($score["score"] !== $prevScore) {
                                        $displayRank = $rank;
                                    }
                                    if ($score["score"] === 0) {
                                        break;
                                    }
                                    $displayName = mb_substr($score["playerName"] . "　　　", 0, 4);
                                    $result .= "\n#{$displayRank} {$displayName}:{$score["score"]}";
                                    $rank++;
                                    $prevScore = $score["score"];
                                }
                            }
                        }
                    }
                    $this->chunithmTools->outputLog("test.txt", $result);
                    break;

                case "mybest":
                case "sc":
                    if ($parametersCount > 1) {
                        $result = TOO_MANY_PARAMETERS;
                    } elseif ($parametersCount < 1) {
                        $result = TOO_FEW_PARAMETERS;
                    } else {
                        $result = $this->chunithmTools->getScore($parameters[0]);
                    }
                    break;

                case "bkl":
                    if ($parametersCount > 0) {
                        $result = TOO_MANY_PARAMETERS;
                    } else {
                        $result = $this->chunithmTools->issueBookmarklet();
                    }
                    break;

                default:
                    $result = "";
            }
        }
        return $result;
    }
}