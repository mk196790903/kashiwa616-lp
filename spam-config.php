<?php
/**
 * 616 お問い合わせフォーム - スパム対策設定ファイル
 * ============================================================
 * このファイルは send-mail.php と同じディレクトリに配置してください。
 * デプロイ後は DEBUG_MODE を false に変更してください。
 * ============================================================
 */

// ─── 動作モード ───
define('DEBUG_MODE', false); // 本番運用時は false に変更

// ─── reCAPTCHA v3 ───
define('RECAPTCHA_SECRET_KEY', '6LexHo0sAAAAAD9qbdloPSdPS-TbRPeAwV5ZxH3H');
define('RECAPTCHA_THRESHOLD', 0.5);

// ─── SMTP設定 ───
define('SMTP_HOST', 'sv114.xbiz.ne.jp');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl'); // tls/587で失敗のためssl/465に変更
define('SMTP_USER', 'info@kashiwa616.com');
define('SMTP_PASS', 'Test2026Pass!');
define('SMTP_FROM_EMAIL', 'info@kashiwa616.com');
define('SMTP_FROM_NAME', '616');

// ─── 管理者通知 ───
define('ADMIN_EMAILS', 'info@comtri.jp,info@kashiwa616.com'); // カンマ区切りで複数可

// ─── 自動返信 ───
define('AUTO_REPLY_ENABLED', true);

// ─── Google Apps Script ───
define('GAS_ENDPOINT', 'https://script.google.com/a/macros/comtri.jp/s/AKfycbxeb5y0l48lUcdXKHvYmSryg6f_9qWCj4K6kCEpAcEBP2MdfR6GmN7RRfNrIcB92ikP/exec');

// ─── スパム対策設定 ───
define('SPAM_MIN_TIME', 5);       // 最小送信時間（秒）
define('SPAM_MAX_TIME', 1800);    // 最大送信時間（秒）= 30分
define('RATE_LIMIT_MAX', 3);      // 1時間あたりの最大送信回数
define('RATE_LIMIT_WINDOW', 3600); // レート制限ウィンドウ（秒）
define('SPAM_SCORE_THRESHOLD', 50); // スパムスコア閾値

// ─── ログ設定 ───
define('LOG_ENABLED', true);
define('LOG_DIR', __DIR__ . '/logs');

// ─── 使い捨てメールドメインリスト ───
define('DISPOSABLE_DOMAINS', serialize([
    'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
    'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
    'dispostable.com', 'maildrop.cc', '10minutemail.com', 'trashmail.com',
    'temp-mail.org', 'fakeinbox.com', 'mailnesia.com', 'tmpmail.net',
    'getnada.com', 'emailondeck.com', 'mohmal.com', 'minuteinbox.com',
    'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org',
    'mailcatch.com', 'meltmail.com', 'harakirimail.com', 'trashymail.com',
    'mailexpire.com', 'tempail.com', 'tempr.email', 'discard.email',
    'mailnull.com', 'spamgourmet.com', 'mytrashmail.com', 'jetable.org'
]));

// ─── 許可する業種リスト ───
define('ALLOWED_INDUSTRIES', serialize([
    '製造業', '卸売業', '小売業', '運送・物流', '建設・不動産',
    'IT・通信', '医療・福祉', '飲食・食品', '教育・学習支援',
    '農業・林業・水産業', '金融・保険', 'サービス業', '官公庁・団体', 'その他'
]));
