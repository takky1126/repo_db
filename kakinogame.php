<?php
error_reporting(0); 
$games_file    = 'games.csv';
$progress_file = 'progress.csv';
$status_file   = 'status_master.csv'; // 新しいテーブル（マスタ）

// --- 1. ファイル初期化（BOM付きUTF-8） ---
if (!file_exists($status_file)) {
    // 初期ステータスを作成
    $initial_status = [
        ["status_id", "status_name"],
        [1, "未着手"], [2, "プレイ中"], [3, "クリア済み"], [4, "一時中断"]
    ];
    $f = fopen($status_file, 'w');
    fwrite($f, "\xEF\xBB\xBF");
    foreach ($initial_status as $line) fputcsv($f, $line);
    fclose($f);
}
if (!file_exists($games_file)) {
    file_put_contents($games_file, "\xEF\xBB\xBF" . "id,title,est,current_status_id\n");
}
if (!file_exists($progress_file)) {
    file_put_contents($progress_file, "\xEF\xBB\xBF" . "log_id,g_id,date,status_id,comment,time\n");
}

// --- 2. マスタデータの読み込み ---
$status_master = [];
if (($h = fopen($status_file, "r")) !== FALSE) {
    fgetcsv($h); // ヘッダー
    while (($d = fgetcsv($h)) !== FALSE) { $status_master[$d[0]] = $d[1]; }
    fclose($h);
}

// --- 3. POST処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 新規登録
    if (isset($_POST['add_game'])) {
        $new_id = time();
        $f = fopen($games_file, 'a');
        fputcsv($f, [$new_id, htmlspecialchars($_POST['title']), (int)$_POST['est_hours'], 1]); // 初期Statusは1(未着手)
        fclose($f);
        
        $f = fopen($progress_file, 'a');
        fputcsv($f, [uniqid(), $new_id, date('Y-m-d'), 1, 'ゲーム開始', 0]);
        fclose($f);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // 進行度（日記）更新
    if (isset($_POST['update_progress'])) {
        $g_id = $_POST['game_id'];
        $new_time = (int)$_POST['current_hours'];
        $prev_time = (int)$_POST['prev_time'];
        $s_id = $_POST['status_id'];

        if ($new_time >= $prev_time) {
            // progress.csv に追加
            $f = fopen($progress_file, 'a');
            fputcsv($f, [uniqid(), $g_id, date('Y-m-d'), $s_id, htmlspecialchars($_POST['status_text']), $new_time]);
            fclose($f);
            
            // games.csv の current_status_id を更新（テーブル連携）
            $rows = [];
            $h = fopen($games_file, "r");
            $rows[] = fgetcsv($h);
            while (($d = fgetcsv($h)) !== FALSE) {
                if ($d[0] == $g_id) $d[3] = $s_id;
                $rows[] = $d;
            }
            fclose($h);
            $f = fopen($games_file, 'w');
            foreach ($rows as $r) { fputcsv($f, $r); }
            fclose($f);
        }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    // ログ削除 / ゲーム削除 は前回と同じロジック（省略せずに実装済み）
    if (isset($_POST['delete_log'])) {
        $rows = []; $h = fopen($progress_file, "r"); $rows[] = fgetcsv($h);
        while (($d = fgetcsv($h)) !== FALSE) { if ($d[0] !== $_POST['log_id']) $rows[] = $d; }
        fclose($h);
        $f = fopen($progress_file, 'w'); foreach ($rows as $r) fputcsv($f, $r); fclose($f);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if (isset($_POST['delete_game'])) {
        $id = $_POST['game_id'];
        $g_rows = []; $h = fopen($games_file, "r"); $g_rows[] = fgetcsv($h);
        while (($d = fgetcsv($h)) !== FALSE) { if ($d[0] != $id) $g_rows[] = $d; }
        fclose($h);
        $f = fopen($games_file, 'w'); foreach ($g_rows as $r) fputcsv($f, $r); fclose($f);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

// --- 4. データ表示用読み込み ---
$games = [];
if (file_exists($games_file)) {
    $f = fopen($games_file, 'r'); fgetcsv($f); 
    while ($d = fgetcsv($f)) { $games[$d[0]] = ['title'=>$d[1], 'est'=>$d[2], 'cur_stat'=>$d[3], 'logs'=>[]]; } 
    fclose($f);
}
if (file_exists($progress_file)) {
    $f = fopen($progress_file, 'r'); fgetcsv($f);
    while ($d = fgetcsv($f)) {
        if (isset($games[$d[1]])) {
            $games[$d[1]]['logs'][] = ['log_id'=>$d[0], 'date'=>$d[2], 'status_id'=>$d[3], 'stat'=>$d[4], 'time'=>$d[5]];
        }
    }
    fclose($f);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>積みゲー日記 v3.0</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; color: #1c1e21; max-width: 900px; margin: 20px auto; padding: 0 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .game-title { font-size: 1.4em; font-weight: bold; color: #1877f2; }
        .log-item { font-size: 0.9em; border-left: 3px solid #ddd; padding: 5px 10px; margin: 5px 0; display: flex; justify-content: space-between; align-items: center; }
        .status-badge { background: #e7f3ff; color: #1877f2; padding: 2px 8px; border-radius: 6px; font-weight: bold; font-size: 0.8em; }
        .history-box { background: #f9f9f9; padding: 10px; border-radius: 4px; margin-top: 10px; max-height: 200px; overflow-y: auto; }
        input, select, button { padding: 8px; border-radius: 4px; border: 1px solid #ddd; }
        .btn-add { background: #1877f2; color: white; border: none; font-weight: bold; }
        .btn-update { background: #42b72a; color: white; border: none; }
        .btn-delete-game { background: #f02849; color: white; border: none; font-size: 0.8em; opacity: 0.7; }
    </style>
</head>
<body>

<h1>🎮 積みゲー管理日記 v3.0</h1>

<div class="card">
    <h3>🆕 新規登録</h3>
    <form method="post">
        <input type="text" name="title" placeholder="タイトル" required>
        <input type="number" name="est_hours" placeholder="クリア想定(h)" required style="width:120px;">
        <button type="submit" name="add_game" class="btn-add">登録</button>
    </form>
</div>

<?php foreach ($games as $id => $game): 
    $latest = end($game['logs']); 
    $current_total = $latest ? (int)$latest['time'] : 0;
?>
<div class="card">
    <div style="display:flex; justify-content:space-between;">
        <span class="game-title"><?= $game['title'] ?></span>
        <form method="post" onsubmit="return confirm('完全に削除しますか？')">
            <input type="hidden" name="game_id" value="<?= $id ?>">
            <button type="submit" name="delete_game" class="btn-delete-game">完全に削除</button>
        </form>
    </div>
    
    <div style="margin: 10px 0;">
        <span style="color:#666;">想定: <?= $game['est'] ?>h</span> |
        <strong>現在:</strong> <span class="status-badge"><?= $status_master[$game['cur_stat']] ?></span>
        (<?= $current_total ?>h)
    </div>

    <div class="history-box">
        <strong>📜 プレイ履歴</strong>
        <?php foreach (array_reverse($game['logs']) as $log): ?>
            <div class="log-item">
                <span>
                    <small><?= $log['date'] ?></small> — 
                    <span class="status-badge"><?= $status_master[$log['status_id']] ?></span>
                    <?= $log['stat'] ?> (<?= $log['time'] ?>h)
                </span>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="log_id" value="<?= $log['log_id'] ?>">
                    <button type="submit" name="delete_log" style="background:none; border:none; color:red; cursor:pointer;">&times;</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
        <form method="post">
            <input type="hidden" name="game_id" value="<?= $id ?>">
            <input type="hidden" name="prev_time" value="<?= $current_total ?>">
            
            <select name="status_id">
                <?php foreach($status_master as $sid => $sname): ?>
                    <option value="<?= $sid ?>" <?= ($sid == $game['cur_stat']) ? 'selected' : '' ?>><?= $sname ?></option>
                <?php endforeach; ?>
            </select>
            
            <input type="text" name="status_text" placeholder="今日の日記" required style="width:200px;">
            <input type="number" name="current_hours" value="<?= $current_total ?>" min="<?= $current_total ?>" required style="width:80px;">
            <button type="submit" name="update_progress" class="btn-update">記録</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

</body>
</html>