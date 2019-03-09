<?php
header("Access-Control-Allow-Origin: *");
?>
var overlay;
var p;
var token;
var playerData;
var scoreList;
var parser;

const difficultyNames = ["Basic", "Advanced", "Expert", "Master"];
const scoreToken = "<?=$_GET['token']?>";

function getPlayerData() {
    p.innerText = "取得中…(プレイヤーデータ)";
    $.ajax("https://chunithm-net.com/mobile/home/playerDataDetail/", {
        type: "GET",
        cache: false,
        timeout: 30000
    }).done(function(result) {
        var doc = parser.parseFromString(result, "text/html");
        var frame = doc.getElementsByClassName("frame01_inside w450")[0];
        if (frame == null) {
            alert("プレイヤーデータを取得中にエラーが発生しました。");
            return;
        }
        var rates = frame.getElementsByClassName("player_rating")[0].innerText;
        var emblemBase = frame.getElementsByClassName("block_classemblem_base");
        var emblemTop = frame.getElementsByClassName("block_classemblem_emblem");

        playerData.name = frame.getElementsByClassName("ml_10")[0].innerText;
        playerData.currentRate = rates.match(/: (\d*\.\d*)/)[1] * 100;
        playerData.maxRate = rates.match(/X (\d*\.\d*)/)[1] * 100;
        playerData.title = frame.getElementsByClassName("player_honor_text")[0].innerText;
        if (emblemBase.length > 0) {
            playerData.emblemBase = emblemBase[0].innerHTML.match(/_(\d\d)/)[1];
        } else {
            playerData.emblemBase = 0;
        }
        if (emblemTop.length > 0) {
            playerData.emblemTop = emblemTop[0].innerHTML.match(/_(\d\d)/)[1];
        } else {
            playerData.emblemTop = 0;
        }
        getScore(0);
    });
}

function getScore(difficulty) {
    p.innerText = "取得中…(" + difficultyNames[difficulty] + ")";
    $.ajax("https://chunithm-net.com/mobile/record/musicGenre/send" + difficultyNames[difficulty], {
        type: "POST",
        cache: false,
        timeout: 30000,
        data: {
            genre: 99,
            token: token
        }
    }).done(function(result) {
        var doc = parser.parseFromString(result, "text/html");
        var songs = doc.getElementsByClassName("musiclist_box");
        var length = songs.length;
        if (length == 0) {
            sendPlayerData(true);
            return;
        }
        for (var i = 0; i < length; i++) {
            var score = songs[i].getElementsByClassName("text_b")[0];
            var id = songs[i].innerHTML.match(/name="idx" value="(\d+)+"/)[1];
            if (score) {
                score = score.innerText.split(',').join('').trim();
            } else {
                score = 0;
            }
            score = parseInt(score);
            id = parseInt(id);
            if (!(id in scoreList)) {
                scoreList[id] = [];
            }
            scoreList[id][difficulty] = score;
        }
        if (difficulty != 3) {
            getScore(difficulty + 1);
        } else {
            sendScore();
        }
    }).fail(function() {
        alert("スコアデータの取得に失敗しました。(" + difficultyNames[difficulty] + ")");
    });
}

function sendScore() {
    var json = JSON.stringify(scoreList);
    p.innerText = "送信中…(スコア)";
    $.ajax("https://chunithmtools.net/scoredata.php", {
        type: "POST",
        cache: false,
        timeout: 30000,
        data: {
            data: json,
            token: scoreToken
        }
    }).done(function(result) {
        if (result == "SUCCESS") {
            sendPlayerData(false);
        } else {
            alert(result);
        }
    }).fail(function() {
        alert("スコアの送信に失敗しました。");
    });
}

function sendPlayerData(isFree) {
    var json = JSON.stringify(playerData);
    p.innerText = "送信中…(プレイヤーデータ)";
    $.ajax("https://chunithmtools.net/playerdata.php", {
        type: "POST",
        cache: false,
        timeout: 30000,
        data: {
            data: json,
            token: scoreToken
        }
    }).done(function(result) {
        if (result == "SUCCESS") {
            if (isFree) {
                alert("プレイヤーデータの送信に成功しました。\n(無料コースでは、プレイヤーデータのみが送信されます。))");
            } else {
                alert("データの送信に成功しました。");
            }
        } else {
            alert(result);
        }
    }).fail(function() {
        alert("プレイヤーデータの送信に失敗しました。");
    });
}

window.onerror = function (message, url, line, column, errorObj) {
	alert("message : " + message
		+ ", url : " + url
		+ ", line : " + line
		+ ", column : " + column
		+ ", error : " + (errorObj ? errorObj.stack : ""));
	return true;
};

(function() {
    alert("スコア取得を開始します。");
    overlay = document.createElement("div");
    overlay.setAttribute("style", "color: #FFF; width: 100%; height: 100%; text-align: left; position: fixed; top: 0; z-index: 99; background-color: rgba(0, 0, 0, 0.7); text-align: center;");
    p = document.createElement("p");
    overlay.appendChild(p);
    document.body.appendChild(overlay);
    token = document.getElementsByName("token")[0].getAttribute("value");
    parser = new DOMParser();
    playerData = {};
    scoreList = {};
    getPlayerData();
})();