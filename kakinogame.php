<?php
error_reporting(0);

// --- 0. 設定とフォルダ準備 ---
$data_dir = 'data/';
if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);

$games_file    = $data_dir . 'games.csv';
$status_file   = $data_dir . 'status_master.csv';

function get_progress_path($g_id) {
    global $data_dir;
    return $data_dir . "progress_{$g_id}.csv";
}

// --- 1. バックアップ（TAR形式）処理 ---
if (isset($_POST['download_all'])) {
    $tarname = $data_dir . 'backup_' . date('Ymd_His') . '.tar';
    try {
        $tar = new PharData($tarname);
        $files = glob($data_dir . '*.csv');
        if (!empty($files)) {
            foreach ($files as $file) { $tar->addFile($file, basename($file)); }
            if (ob_get_length()) ob_end_clean();
            header('Content-Type: application/x-tar');
            header('Content-Disposition: attachment; filename="' . basename($tarname) . '"');
            header('Content-Length: ' . filesize($tarname));
            readfile($tarname);
            unlink($tarname);
            exit;
        }
    } catch (Exception $e) { die("SYSTEM ERROR: " . $e->getMessage()); }
}

// --- 2. ファイル初期化 ---
if (!file_exists($status_file)) {
    $initial_status = [["status_id", "status_name"],[1, "STAY (未着手)"], [2, "PLAYING (進行中)"], [3, "CLEARED (完了)"], [4, "PAUSED (中断)"]];
    $f = fopen($status_file, 'w'); fwrite($f, "\xEF\xBB\xBF");
    foreach ($initial_status as $line) fputcsv($f, $line); fclose($f);
}
if (!file_exists($games_file)) file_put_contents($games_file, "\xEF\xBB\xBF" . "id,title,est,current_status_id\n");

$status_master = [];
if (($h = fopen($status_file, "r")) !== FALSE) {
    fgetcsv($h);
    while (($d = fgetcsv($h)) !== FALSE) { $status_master[$d[0]] = $d[1]; }
    fclose($h);
}

// --- 3. POST処理（ロジックは維持） ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_game'])) {
        $new_id = time();
        $f = fopen($games_file, 'a');
        fputcsv($f, [$new_id, htmlspecialchars($_POST['title']), (int)$_POST['est_hours'], 1]);
        fclose($f);
        $p_file = get_progress_path($new_id);
        file_put_contents($p_file, "\xEF\xBB\xBF" . "log_id,g_id,date,status_id,comment,time\n");
        $f = fopen($p_file, 'a');
        fputcsv($f, [uniqid(), $new_id, date('Y-m-d'), 1, 'スタート', 0]);
        fclose($f);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if (isset($_POST['update_progress'])) {
        $g_id = $_POST['game_id'];
        $new_time = (int)$_POST['current_hours'];
        if ($new_time >= (int)$_POST['prev_time']) {
            $f = fopen(get_progress_path($g_id), 'a');
            fputcsv($f, [uniqid(), $g_id, date('Y-m-d'), $_POST['status_id'], htmlspecialchars($_POST['status_text']), $new_time]);
            fclose($f);
            $rows = []; $h = fopen($games_file, "r"); $rows[] = fgetcsv($h);
            while (($d = fgetcsv($h)) !== FALSE) { if ($d[0] == $g_id) $d[3] = $_POST['status_id']; $rows[] = $d; }
            fclose($h);
            $f = fopen($games_file, 'w'); foreach ($rows as $r) fputcsv($f, $r); fclose($f);
        }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if (isset($_POST['delete_log'])) {
        $p_file = get_progress_path($_POST['game_id']);
        $rows = []; $h = fopen($p_file, "r"); $rows[] = fgetcsv($h);
        while (($d = fgetcsv($h)) !== FALSE) { if ($d[0] !== $_POST['log_id']) $rows[] = $d; }
        fclose($h);
        $f = fopen($p_file, 'w'); foreach ($rows as $r) fputcsv($f, $r); fclose($f);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if (isset($_POST['delete_game'])) {
        $id = $_POST['game_id'];
        $g_rows = []; $h = fopen($games_file, "r"); $g_rows[] = fgetcsv($h);
        while (($d = fgetcsv($h)) !== FALSE) { if ($d[0] != $id) $g_rows[] = $d; }
        fclose($h);
        $f = fopen($games_file, 'w'); foreach ($g_rows as $r) fputcsv($f, $r); fclose($f);
        if (file_exists(get_progress_path($id))) unlink(get_progress_path($id));
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

// --- 4. データ読み込み ---
$games = [];
if (file_exists($games_file)) {
    $f = fopen($games_file, 'r'); fgetcsv($f); 
    while ($d = fgetcsv($f)) { 
        $g_id = $d[0];
        $games[$g_id] = ['title'=>$d[1], 'est'=>$d[2], 'cur_stat'=>$d[3], 'logs'=>[]];
        $p_file = get_progress_path($g_id);
        if (file_exists($p_file)) {
            $pf = fopen($p_file, 'r'); fgetcsv($pf);
            while ($ld = fgetcsv($pf)) { $games[$g_id]['logs'][] = ['log_id'=>$ld[0], 'date'=>$ld[2], 'status_id'=>$ld[3], 'stat'=>$ld[4], 'time'=>$ld[5]]; }
            fclose($pf);
        }
    } 
    fclose($f);
}
$view_mode = $_GET['view'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title> 積みゲー管理アプリ </title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
        
        body { background: #0a0a0c; color: #00ffff; font-family: 'Share Tech Mono', monospace; max-width: 900px; margin: 20px auto; padding: 0 20px; line-height: 1.6; }
        
        /* カードデザイン */
        .card { background: #121217; padding: 20px; border: 1px solid #00ffff; box-shadow: 0 0 10px rgba(236, 231, 231, 0.2); margin-bottom: 25px; border-radius: 0; position: relative; }
        .card::before { content: ""; position: absolute; top: 0; left: 0; border-top: 10px solid #00ffff; border-right: 10px solid transparent; }

        /* ヘッダー */
        .header-area { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ff00ff; padding-bottom: 10px; margin-bottom: 30px; text-shadow: 0 0 10px #ff00ff; }
        h1 { color: #ff00ff; letter-spacing: 3px; font-size: 2em; }

        /* ボタン */
        .btn { padding: 10px 20px; border-radius: 0; border: 1px solid; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; transition: 0.3s; background: transparent; font-family: inherit; }
        .btn-blue { color: #00ffff; border-color: #00ffff; }
        .btn-blue:hover { background: #00ffff; color: #000; box-shadow: 0 0 20px #00ffff; }
        .btn-pink { color: #ff00ff; border-color: #ff00ff; }
        .btn-pink:hover { background: #ff00ff; color: #000; box-shadow: 0 0 20px #ff00ff; }
        .btn-red { color: #ff4d4d; border-color: #ff4d4d; font-size: 0.8em; }
        .btn-red:hover { background: #ff4d4d; color: #000; }

        /* ゲーム情報 */
        .game-title { font-size: 1.6em; color: #00ffff; text-shadow: 0 0 5px rgba(0,255,255,0.5); }
        .status-badge { color: #ffff00; border: 1px solid #ffff00; padding: 1px 10px; font-size: 0.8em; }
        
        /* 履歴ボックス */
        .history-box { background: #000; border: 1px solid #333; padding: 10px; margin-top: 15px; max-height: 200px; overflow-y: auto; color: #dededeff; }
        .history-box::-webkit-scrollbar { width: 5px; }
        .history-box::-webkit-scrollbar-thumb { background: #333; }

        /* テーブル閲覧用 */
        table { width: 100%; border-collapse: collapse; color: #00ffff; border: 1px solid #00ffff; }
        th, td { border: 1px solid #00ffff; padding: 10px; text-align: left; }
        th { background: rgba(0,255,255,0.1); color: #ff00ff; }

        input, select { background: #000; border: 1px solid #00ffff; color: #00ffff; padding: 8px; font-family: inherit; }
        input:focus { outline: none; box-shadow: 0 0 10px #00ffff; }
    </style>
</head>
<body>

<div class="header-area">
    <h1>積みゲー管理アプリ</h1>
    <div>
        <?php if($view_mode === 'dashboard'): ?>
            <a href="?view=list" class="btn btn-blue">DATA_PAGE</a>
            <form method="post" style="display:inline;">
                <button type="submit" name="download_all" class="btn btn-pink">DOWNLOAD_ALL</button>
            </form>
        <?php else: ?>
            <a href="?" class="btn btn-blue">RETURN_MAINPAGE</a>
        <?php endif; ?>
    </div>
</div>

<?php if($view_mode === 'dashboard'): ?>
    <div class="card">
        <h3> ADD_NEW_GAME</h3>
        <form method="post">
            <input type="text" name="title" placeholder="TITLE" required>
            <input type="number" name="est_hours" placeholder="LENGTH(h)" required style="width:80px;">
            <button type="submit" name="add_game" class="btn btn-blue">REGISTER</button>
        </form>
    </div>

    <?php foreach ($games as $id => $game): 
        $latest = end($game['logs']); $current_total = $latest ? (int)$latest['time'] : 0;
    ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="game-title"><?= $game['title'] ?></span>
            <form method="post" onsubmit="return confirm('ERASE DATA?')">
                <input type="hidden" name="game_id" value="<?= $id ?>">
                <button type="submit" name="delete_game" class="btn btn-red">DELETE</button>
            </form>
        </div>
        <div style="margin: 10px 0;">
            LENGTH: <?= $game['est'] ?>h | STATUS: <span class="status-badge"><?= $status_master[$game['cur_stat']] ?></span> | TOTAL: <?= $current_total ?>h
        </div>
        <div class="history-box">
            <?php foreach (array_reverse($game['logs']) as $log): ?>
                <div style="font-size:0.85em; border-bottom:1px solid #222; padding:5px 0; display:flex; justify-content:space-between;">
                    <span><span style="color:#ff00ff;"><?= $log['date'] ?></span> >> <?= $log['stat'] ?> (<?= $log['time'] ?>h)</span>
                    <form method="post">
                        <input type="hidden" name="game_id" value="<?= $id ?>"><input type="hidden" name="log_id" value="<?= $log['log_id'] ?>">
                        <button type="submit" name="delete_log" style="border:none; color:#ff4d4d; background:none; cursor:pointer;">[X]</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="game_id" value="<?= $id ?>"><input type="hidden" name="prev_time" value="<?= $current_total ?>">
            <select name="status_id"><?php foreach($status_master as $sid => $sname): ?>
                <option value="<?= $sid ?>" <?= ($sid == $game['cur_stat']) ? 'selected' : '' ?>><?= $sname ?></option>
            <?php endforeach; ?></select>
            <input type="text" name="status_text" placeholder="LOG_COMMENT" required style="flex-grow:1;">
            <input type="number" name="current_hours" value="<?= $current_total ?>" min="<?= $current_total ?>" style="width:60px;">
            <button type="submit" name="update_progress" class="btn btn-blue">UPDATE</button>
        </form>
    </div>
    <?php endforeach; ?>

<?php else: ?>
    <div class="card">
        <h3>>> DATABASE_RECORDS</h3>
        <?php
        $files = glob($data_dir . '*.csv');
        echo "<ul style='list-style:none; padding:0;'>";
        foreach ($files as $f) {
            $fname = basename($f);
            echo "<li style='margin-bottom:10px;'>> <a href='?view=table&file=$fname' style='color:#ffff00; text-decoration:none;'>$fname</a></li>";
        }
        echo "</ul>";

        if ($view_mode === 'table' && isset($_GET['file'])) {
            $target = $data_dir . basename($_GET['file']);
            if (file_exists($target)) {
                echo "<h4 style='color:#ff00ff;'>FILE_NAME: " . htmlspecialchars($_GET['file']) . "</h4>";
                echo "<table>";
                if (($handle = fopen($target, "r")) !== FALSE) {
                    $is_header = true;
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        echo "<tr>";
                        foreach ($data as $cell) { echo $is_header ? "<th>".htmlspecialchars($cell)."</th>" : "<td>".htmlspecialchars($cell)."</td>"; }
                        echo "</tr>"; $is_header = false;
                    }
                    fclose($handle);
                }
                echo "</table>";
            }
        }
        ?>
    </div>
<?php endif; ?>

</body>
</html>