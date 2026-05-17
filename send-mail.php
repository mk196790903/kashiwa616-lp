<?php
/**
 * 616 お問い合わせフォーム - メール送信 + スパム判定処理
 * ============================================================
 * WordPress環境のPHPMailerを使用したSMTP送信
 * 多層スパム対策統合
 * ============================================================
 */

// エラーハンドリング
error_reporting(E_ALL);
ini_set('display_errors', '0');

// 致命的エラーもJSONで返す
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'サーバーエラーが発生しました。管理者にお問い合わせください。',
            'debug' => defined('DEBUG_MODE') && DEBUG_MODE ? $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : null
        ]);
    }
});

// CORS（同一ドメイン想定だが念のため）
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: https://kashiwa616.com');

// POST以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不正なリクエストです。']);
    exit;
}

// ─── 設定ファイル読み込み ───
$configPath = __DIR__ . '/spam-config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'システムエラーが発生しました。管理者にお問い合わせください。']);
    exit;
}
require_once $configPath;

// ─── デバッグモード ───
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ─── PHPMailer読み込み（WordPress環境） ───
$docRoot = $_SERVER['DOCUMENT_ROOT'];
$wpMailerPath = $docRoot . '/wp-includes/PHPMailer/PHPMailer.php';
$wpSmtpPath = $docRoot . '/wp-includes/PHPMailer/SMTP.php';
$wpExceptionPath = $docRoot . '/wp-includes/PHPMailer/Exception.php';

$phpmailerLoaded = false;
if (file_exists($wpMailerPath)) {
    require_once $wpMailerPath;
    require_once $wpSmtpPath;
    require_once $wpExceptionPath;
    $phpmailerLoaded = true;
}

if (!$phpmailerLoaded) {
    writeLog('PHPMailerが見つかりません: ' . $wpMailerPath, 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'メール送信機能が利用できません。']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/* ============================================================
   ログ機能
   ============================================================ */
function writeLog($message, $type = 'info') {
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) return;
    if (!defined('LOG_DIR')) return;

    $logDir = LOG_DIR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/contact_' . date('Y-m') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $entry = "[{$timestamp}] [{$type}] [IP:{$ip}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function writeSpamLog($data, $score, $reasons) {
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) return;
    if (!defined('LOG_DIR')) return;

    $logDir = LOG_DIR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/spam_' . date('Y-m') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $entry = "[{$timestamp}] [IP:{$ip}] Score:{$score} Reasons:" . implode(',', $reasons)
           . " Email:" . ($data['email'] ?? 'N/A') . " Name:" . ($data['name'] ?? 'N/A') . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/* ============================================================
   ユーティリティ
   ============================================================ */
function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function sanitizeInput($str) {
    $str = trim($str);
    $str = strip_tags($str);
    $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    // NULLバイト除去
    $str = str_replace(chr(0), '', $str);
    return $str;
}

function generateInquiryNumber() {
    return 'INQ-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/* ============================================================
   スパムチェック（多層防御）
   ============================================================ */
function performSpamCheck($data) {
    $score = 0;
    $reasons = [];

    // --- Layer 1: reCAPTCHA v3 ---
    $recaptchaToken = $data['recaptcha_token'] ?? '';
    if (empty($recaptchaToken)) {
        $score += 40;
        $reasons[] = 'no_recaptcha_token';
    } else {
        $recaptchaResult = verifyRecaptcha($recaptchaToken);
        if (!$recaptchaResult['success']) {
            $score += 40;
            $reasons[] = 'recaptcha_failed';
        } elseif ($recaptchaResult['score'] < RECAPTCHA_THRESHOLD) {
            $score += 30;
            $reasons[] = 'recaptcha_low_score(' . $recaptchaResult['score'] . ')';
        }
    }

    // --- Layer 2: ハニーポット ---
    if (!empty($data['website']) || !empty($data['url_confirm'])) {
        $score += 100; // 即ブロック
        $reasons[] = 'honeypot_triggered';
    }

    // --- Layer 3: タイムスタンプ検証 ---
    $formTimestamp = intval($data['form_timestamp'] ?? 0);
    if ($formTimestamp > 0) {
        $elapsed = (time() * 1000 - $formTimestamp) / 1000; // ミリ秒→秒
        if ($elapsed < SPAM_MIN_TIME) {
            $score += 30;
            $reasons[] = 'too_fast(' . round($elapsed, 1) . 's)';
        }
        if ($elapsed > SPAM_MAX_TIME) {
            $score += 10;
            $reasons[] = 'too_slow(' . round($elapsed, 1) . 's)';
        }
    } else {
        $score += 20;
        $reasons[] = 'no_timestamp';
    }

    // --- Layer 4: レート制限 ---
    $ip = getClientIP();
    $rateLimitResult = checkRateLimit($ip);
    if (!$rateLimitResult) {
        $score += 50;
        $reasons[] = 'rate_limit_exceeded';
    }

    // --- Layer 5: 入力値チェック ---
    $email = $data['email'] ?? '';
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    $disposableDomains = defined('DISPOSABLE_DOMAINS') ? unserialize(DISPOSABLE_DOMAINS) : [];
    if (in_array($domain, $disposableDomains)) {
        $score += 30;
        $reasons[] = 'disposable_email(' . $domain . ')';
    }

    // メッセージ内のURL数チェック
    $message = $data['message'] ?? '';
    $urlCount = preg_match_all('/https?:\/\/[^\s]+/', $message);
    if ($urlCount > 3) {
        $score += 20;
        $reasons[] = 'too_many_urls(' . $urlCount . ')';
    }

    // 日本語を含むか（日本向けフォーム）
    $combinedText = ($data['name'] ?? '') . ($data['company'] ?? '') . ($data['message'] ?? '');
    if (!preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $combinedText)) {
        $score += 15;
        $reasons[] = 'no_japanese_chars';
    }

    // 業種の検証
    $allowedIndustries = defined('ALLOWED_INDUSTRIES') ? unserialize(ALLOWED_INDUSTRIES) : [];
    $industry = $data['industry'] ?? '';
    if (!empty($allowedIndustries) && !in_array($industry, $allowedIndustries)) {
        $score += 20;
        $reasons[] = 'invalid_industry';
    }

    return [
        'score' => $score,
        'reasons' => $reasons,
        'is_spam' => $score >= SPAM_SCORE_THRESHOLD
    ];
}

/* ============================================================
   reCAPTCHA v3 検証
   ============================================================ */
function verifyRecaptcha($token) {
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => getClientIP()
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        writeLog('reCAPTCHA API呼び出し失敗', 'error');
        return ['success' => false, 'score' => 0];
    }

    $response = json_decode($result, true);
    return [
        'success' => $response['success'] ?? false,
        'score' => $response['score'] ?? 0,
        'action' => $response['action'] ?? ''
    ];
}

/* ============================================================
   レート制限（ファイルベース）
   ============================================================ */
function checkRateLimit($ip) {
    if (!defined('LOG_DIR')) return true;

    $rateLimitDir = LOG_DIR . '/ratelimit';
    if (!is_dir($rateLimitDir)) {
        @mkdir($rateLimitDir, 0755, true);
    }

    $ipHash = md5($ip);
    $filePath = $rateLimitDir . '/' . $ipHash . '.json';

    $now = time();
    $window = defined('RATE_LIMIT_WINDOW') ? RATE_LIMIT_WINDOW : 3600;
    $maxRequests = defined('RATE_LIMIT_MAX') ? RATE_LIMIT_MAX : 3;

    $records = [];
    if (file_exists($filePath)) {
        $content = @file_get_contents($filePath);
        $records = json_decode($content, true) ?: [];
    }

    // 期限切れのレコードを除去
    $records = array_filter($records, function($ts) use ($now, $window) {
        return ($now - $ts) < $window;
    });

    if (count($records) >= $maxRequests) {
        writeLog("レート制限超過: IP={$ip}, Count=" . count($records), 'spam');
        return false;
    }

    // 新しいレコード追加
    $records[] = $now;
    @file_put_contents($filePath, json_encode(array_values($records)), LOCK_EX);

    return true;
}

/* ============================================================
   GASへのデータ送信
   ============================================================ */
function sendToGAS($data) {
    if (!defined('GAS_ENDPOINT') || empty(GAS_ENDPOINT)) {
        writeLog('GASエンドポイント未設定', 'warning');
        return false;
    }

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'timeout' => 15,
            'follow_location' => true
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents(GAS_ENDPOINT, false, $context);

    if ($result === false) {
        writeLog('GAS送信失敗', 'error');
        return false;
    }

    writeLog('GAS送信成功', 'info');
    return true;
}

/* ============================================================
   HTMLメール生成
   ============================================================ */
function generateAdminMailHTML($data, $inquiryNumber) {
    $name = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
    $company = htmlspecialchars($data['company'], ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($data['phone'] ?? '（未入力）', ENT_QUOTES, 'UTF-8');
    $homepage = htmlspecialchars($data['homepage'] ?? '（未入力）', ENT_QUOTES, 'UTF-8');
    $industry = htmlspecialchars($data['industry'], ENT_QUOTES, 'UTF-8');
    $message = nl2br(htmlspecialchars($data['message'], ENT_QUOTES, 'UTF-8'));
    $spamScore = $data['spam_score'] ?? 0;
    $datetime = date('Y年m月d日 H:i');
    $ssUrl = 'https://docs.google.com/spreadsheets/d/1r7VY1dhQsOB3DtB_b_YRLl2Kkrx0qgmUM2HdWzgFlmI/edit';

    $html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head>';
    $html .= '<body style="margin:0;padding:0;background:#f4f6f8;font-family:\'Hiragino Kaku Gothic ProN\',\'Hiragino Sans\',\'Noto Sans JP\',\'Meiryo\',sans-serif;">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;"><tr><td align="center">';
    $html .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';
    // ヘッダー
    $html .= '<tr><td style="background:#1a2744;padding:24px 32px;text-align:center;">';
    $html .= '<h1 style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.05em;">新しいお問い合わせ</h1>';
    $html .= '</td></tr>';
    // 受付情報
    $html .= '<tr><td style="padding:24px 32px 0;">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4ff;border-radius:6px;padding:16px;">';
    $html .= '<tr><td style="padding:8px 16px;font-size:13px;color:#6b7280;">受付番号</td>';
    $html .= '<td style="padding:8px 16px;font-size:15px;font-weight:700;color:#1a2744;">' . $inquiryNumber . '</td></tr>';
    $html .= '<tr><td style="padding:8px 16px;font-size:13px;color:#6b7280;">受付日時</td>';
    $html .= '<td style="padding:8px 16px;font-size:14px;color:#1f2937;">' . $datetime . '</td></tr>';
    $html .= '<tr><td style="padding:8px 16px;font-size:13px;color:#6b7280;">スパムスコア</td>';
    $html .= '<td style="padding:8px 16px;font-size:14px;color:#1f2937;">' . $spamScore . '点</td></tr>';
    $html .= '</table></td></tr>';
    // お問い合わせ内容
    $html .= '<tr><td style="padding:24px 32px;">';
    $html .= '<h2 style="margin:0 0 16px;font-size:15px;color:#1a2744;border-bottom:2px solid #1a2744;padding-bottom:8px;">お問い合わせ内容</h2>';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;width:120px;vertical-align:top;border-bottom:1px solid #e5e7eb;">お名前</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $name . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">会社名</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $company . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">メール</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;"><a href="mailto:' . $email . '" style="color:#3b82f6;">' . $email . '</a></td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">電話番号</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $phone . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">HP</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $homepage . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">業種</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $industry . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;">内容</td><td style="padding:10px 0;color:#1f2937;line-height:1.8;">' . $message . '</td></tr>';
    $html .= '</table></td></tr>';
    // スプレッドシートリンク
    $html .= '<tr><td style="padding:24px 32px;text-align:center;">';
    $html .= '<a href="' . $ssUrl . '" target="_blank" style="display:inline-block;background:#1877e2;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:6px;font-size:14px;font-weight:700;letter-spacing:0.05em;">スプレッドシートで確認する</a>';
    $html .= '</td></tr>';
    // フッター
    $html .= '<tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">';
    $html .= '<p style="margin:0;font-size:12px;color:#9ca3af;">このメールは 616 お問い合わせフォームから自動送信されています。</p>';
    $html .= '</td></tr>';
    $html .= '</table></td></tr></table></body></html>';

    return $html;
}

function generateAdminMailPlain($data, $inquiryNumber) {
    $datetime = date('Y年m月d日 H:i');
    $phone = $data['phone'] ?? '（未入力）';
    $homepage = $data['homepage'] ?? '（未入力）';
    $ssUrl = 'https://docs.google.com/spreadsheets/d/1r7VY1dhQsOB3DtB_b_YRLl2Kkrx0qgmUM2HdWzgFlmI/edit';

    $text = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "　新しいお問い合わせ\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "受付番号：{$inquiryNumber}\n";
    $text .= "受付日時：{$datetime}\n\n";
    $text .= "──────────────────────────────\n\n";
    $text .= "お名前：{$data['name']}\n";
    $text .= "会社名：{$data['company']}\n";
    $text .= "メール：{$data['email']}\n";
    $text .= "電話番号：{$phone}\n";
    $text .= "HP：{$homepage}\n";
    $text .= "業種：{$data['industry']}\n\n";
    $text .= "【お困りごと・お問い合わせ内容】\n";
    $text .= "{$data['message']}\n\n";
    $text .= "──────────────────────────────\n";
    $text .= "▼ スプレッドシートで確認\n";
    $text .= "{$ssUrl}\n";
    $text .= "──────────────────────────────\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "このメールは 616 お問い合わせフォームから\n";
    $text .= "自動送信されています。\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    return $text;
}

function generateAutoReplyHTML($data, $inquiryNumber) {
    $name = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
    $company = htmlspecialchars($data['company'], ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($data['phone'] ?? '（未入力）', ENT_QUOTES, 'UTF-8');
    $homepage = htmlspecialchars($data['homepage'] ?? '（未入力）', ENT_QUOTES, 'UTF-8');
    $industry = htmlspecialchars($data['industry'], ENT_QUOTES, 'UTF-8');
    $message = nl2br(htmlspecialchars($data['message'], ENT_QUOTES, 'UTF-8'));
    $datetime = date('Y年m月d日 H:i');

    $html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head>';
    $html .= '<body style="margin:0;padding:0;background:#f4f6f8;font-family:\'Hiragino Kaku Gothic ProN\',\'Hiragino Sans\',\'Noto Sans JP\',\'Meiryo\',sans-serif;">';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;"><tr><td align="center">';
    $html .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';
    // ヘッダー
    $html .= '<tr><td style="background:#1a2744;padding:24px 32px;text-align:center;">';
    $html .= '<h1 style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.05em;">お問い合わせありがとうございます</h1>';
    $html .= '</td></tr>';
    // 本文
    $html .= '<tr><td style="padding:32px;">';
    $html .= '<p style="margin:0 0 20px;font-size:14px;color:#1f2937;line-height:1.8;">';
    $html .= $name . ' 様<br><br>';
    $html .= 'この度は 616 にお問い合わせいただき、誠にありがとうございます。<br>';
    $html .= '以下の内容でお問い合わせを受け付けました。<br>';
    $html .= '担当者より折り返しご連絡いたしますので、しばらくお待ちください。</p>';
    // 受付情報
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:6px;padding:16px;margin-bottom:20px;">';
    $html .= '<tr><td style="padding:8px 16px;font-size:13px;color:#6b7280;">受付番号</td>';
    $html .= '<td style="padding:8px 16px;font-size:15px;font-weight:700;color:#1a2744;">' . $inquiryNumber . '</td></tr>';
    $html .= '<tr><td style="padding:8px 16px;font-size:13px;color:#6b7280;">受付日時</td>';
    $html .= '<td style="padding:8px 16px;font-size:14px;color:#1f2937;">' . $datetime . '</td></tr>';
    $html .= '</table>';
    // 内容テーブル
    $html .= '<h2 style="margin:0 0 16px;font-size:15px;color:#1a2744;border-bottom:2px solid #1a2744;padding-bottom:8px;">お問い合わせ内容</h2>';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;width:120px;vertical-align:top;border-bottom:1px solid #e5e7eb;">お名前</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $name . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">会社名</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $company . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">メール</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $email . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">電話番号</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $phone . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">HP</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $homepage . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;border-bottom:1px solid #e5e7eb;">業種</td><td style="padding:10px 0;color:#1f2937;border-bottom:1px solid #e5e7eb;">' . $industry . '</td></tr>';
    $html .= '<tr><td style="padding:10px 0;color:#6b7280;vertical-align:top;">内容</td><td style="padding:10px 0;color:#1f2937;line-height:1.8;">' . $message . '</td></tr>';
    $html .= '</table>';
    $html .= '<p style="margin:24px 0 0;font-size:13px;color:#6b7280;line-height:1.8;">';
    $html .= '※ このメールは自動送信されています。<br>';
    $html .= '※ このメールにお心当たりのない場合は、お手数ですが破棄してください。</p>';
    $html .= '</td></tr>';
    // フッター
    $html .= '<tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">';
    $html .= '<p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#1a2744;">616（ロクイチロク）</p>';
    $html .= '<p style="margin:0;font-size:12px;color:#9ca3af;">';
    $html .= '<a href="https://kashiwa616.com/" style="color:#3b82f6;text-decoration:none;">https://kashiwa616.com/</a></p>';
    $html .= '</td></tr>';
    $html .= '</table></td></tr></table></body></html>';

    return $html;
}

function generateAutoReplyPlain($data, $inquiryNumber) {
    $datetime = date('Y年m月d日 H:i');
    $phone = $data['phone'] ?? '（未入力）';
    $homepage = $data['homepage'] ?? '（未入力）';

    $text = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "　お問い合わせありがとうございます\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "{$data['name']} 様\n\n";
    $text .= "この度は 616 にお問い合わせいただき、\n";
    $text .= "誠にありがとうございます。\n\n";
    $text .= "以下の内容でお問い合わせを受け付けました。\n";
    $text .= "担当者より折り返しご連絡いたしますので、\n";
    $text .= "しばらくお待ちください。\n\n";
    $text .= "受付番号：{$inquiryNumber}\n";
    $text .= "受付日時：{$datetime}\n\n";
    $text .= "──────────────────────────────\n\n";
    $text .= "お名前：{$data['name']}\n";
    $text .= "会社名：{$data['company']}\n";
    $text .= "メール：{$data['email']}\n";
    $text .= "電話番号：{$phone}\n";
    $text .= "HP：{$homepage}\n";
    $text .= "業種：{$data['industry']}\n\n";
    $text .= "【お困りごと・お問い合わせ内容】\n";
    $text .= "{$data['message']}\n\n";
    $text .= "──────────────────────────────\n\n";
    $text .= "※ このメールは自動送信されています。\n";
    $text .= "※ このメールにお心当たりのない場合は、\n";
    $text .= "　 お手数ですが破棄してください。\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "616（ロクイチロク）\n";
    $text .= "https://kashiwa616.com/\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    return $text;
}

/* ============================================================
   メール送信
   ============================================================ */
function sendMail($to, $subject, $htmlBody, $plainBody, $replyTo = '') {
    $mail = new PHPMailer(true);

    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 30;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        // 複数宛先対応
        $recipients = array_map('trim', explode(',', $to));
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
            }
        }

        if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        writeLog('メール送信失敗: ' . $mail->ErrorInfo, 'error');
        return false;
    }
}

/* ============================================================
   メイン処理
   ============================================================ */
try {
    // 入力データ取得・サニタイズ
    $formData = [
        'name' => sanitizeInput($_POST['name'] ?? ''),
        'company' => sanitizeInput($_POST['company'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => sanitizeInput($_POST['phone'] ?? ''),
        'homepage' => sanitizeInput($_POST['homepage'] ?? ''),
        'industry' => sanitizeInput($_POST['industry'] ?? ''),
        'message' => sanitizeInput($_POST['message'] ?? ''),
        'recaptcha_token' => $_POST['recaptcha_token'] ?? '',
        'form_timestamp' => $_POST['form_timestamp'] ?? '',
        'website' => $_POST['website'] ?? '',
        'url_confirm' => $_POST['url_confirm'] ?? ''
    ];

    // 必須フィールドチェック
    $required = ['name', 'company', 'email', 'industry', 'message'];
    foreach ($required as $field) {
        if (empty($formData[$field])) {
            echo json_encode(['success' => false, 'message' => '必須項目を入力してください。']);
            exit;
        }
    }

    // メールアドレスバリデーション
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '正しいメールアドレスを入力してください。']);
        exit;
    }

    // スパムチェック
    $spamResult = performSpamCheck($formData);
    $formData['spam_score'] = $spamResult['score'];

    writeLog("スパムチェック完了: Score={$spamResult['score']}, IsSpam=" . ($spamResult['is_spam'] ? 'YES' : 'NO'));

    if ($spamResult['is_spam']) {
        writeSpamLog($formData, $spamResult['score'], $spamResult['reasons']);

        // スパムの場合でもユーザーには成功を返す（情報を与えない）
        $fakeNumber = generateInquiryNumber();
        echo json_encode(['success' => true, 'inquiry_number' => $fakeNumber]);
        exit;
    }

    // 問い合わせ番号生成
    $inquiryNumber = generateInquiryNumber();

    // 管理者メール送信
    $adminSubject = "【616】新しいお問い合わせ [{$inquiryNumber}]";
    $adminHTML = generateAdminMailHTML($formData, $inquiryNumber);
    $adminPlain = generateAdminMailPlain($formData, $inquiryNumber);
    $adminResult = sendMail(ADMIN_EMAILS, $adminSubject, $adminHTML, $adminPlain, $formData['email']);

    if (!$adminResult) {
        writeLog('管理者メール送信失敗', 'error');
    }

    // 自動返信メール
    if (defined('AUTO_REPLY_ENABLED') && AUTO_REPLY_ENABLED) {
        $replySubject = "【616】お問い合わせありがとうございます [{$inquiryNumber}]";
        $replyHTML = generateAutoReplyHTML($formData, $inquiryNumber);
        $replyPlain = generateAutoReplyPlain($formData, $inquiryNumber);
        $replyResult = sendMail($formData['email'], $replySubject, $replyHTML, $replyPlain);

        if (!$replyResult) {
            writeLog('自動返信メール送信失敗: ' . $formData['email'], 'error');
        }
    }

    // GASへデータ送信
    $gasData = [
        'inquiry_number' => $inquiryNumber,
        'datetime' => date('Y-m-d H:i:s'),
        'name' => $formData['name'],
        'company' => $formData['company'],
        'email' => $formData['email'],
        'phone' => $formData['phone'],
        'homepage' => $formData['homepage'],
        'industry' => $formData['industry'],
        'message' => $formData['message'],
        'spam_score' => $spamResult['score'],
        'ip' => getClientIP()
    ];
    sendToGAS($gasData);

    writeLog("問い合わせ受付完了: {$inquiryNumber}");

    echo json_encode([
        'success' => true,
        'inquiry_number' => $inquiryNumber
    ]);

} catch (Exception $e) {
    writeLog('システムエラー: ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'システムエラーが発生しました。時間を置いて再度お試しください。'
    ]);
}
