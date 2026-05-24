<?php
/**
 * 616 お問い合わせフォーム - 設定ファイル（サンプル）
 * ============================================================
 * このファイルをコピーして spam-config.php を作成し、
 * サーバー上で値を設定してください。
 *
 * 重要: spam-config.php にはSMTPパスワード等の機密情報が入るため、
 * Git管理（GitHub公開）しないでください。
 */

// ─── 動作モード ───
define('DEBUG_MODE', false);

// ─── reCAPTCHA v3 ───
// Google reCAPTCHA 管理画面で取得した「シークレットキー」を設定
define('RECAPTCHA_SECRET_KEY', 'PUT_YOUR_RECAPTCHA_SECRET_KEY_HERE');
define('RECAPTCHA_THRESHOLD', 0.5);

// ─── SMTP設定 ───
define('SMTP_HOST', 'YOUR_SMTP_HOST');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl'); // 例: ssl または tls
define('SMTP_USER', 'YOUR_SMTP_USER');
define('SMTP_PASS', 'YOUR_SMTP_PASSWORD');
define('SMTP_FROM_EMAIL', 'YOUR_FROM_EMAIL');
define('SMTP_FROM_NAME', '616');

// ─── 管理者通知 ───
// カンマ区切りで複数指定可
define('ADMIN_EMAILS', 'info@example.com');

// ─── 自動返信 ───
define('AUTO_REPLY_ENABLED', true);

// ─── Google Apps Script ───
define('GAS_ENDPOINT', '');

// ─── スパム対策設定 ───
define('SPAM_MIN_TIME', 5);
define('SPAM_MAX_TIME', 1800);
define('RATE_LIMIT_MAX', 3);
define('RATE_LIMIT_WINDOW', 3600);
define('SPAM_SCORE_THRESHOLD', 50);

// ─── ログ設定 ───
define('LOG_ENABLED', true);
define('LOG_DIR', __DIR__ . '/logs');

// ─── 使い捨てメールドメインリスト ───
define('DISPOSABLE_DOMAINS', serialize([
    'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
]));

// ─── 許可する業種リスト（任意入力時のみ検証） ───
define('ALLOWED_INDUSTRIES', serialize([
    '製造業', '卸売業', '小売業', '運送・物流', '建設・不動産',
    'IT・通信', '医療・福祉', '飲食・食品', '教育・学習支援',
    '農業・林業・水産業', '金融・保険', 'サービス業', '官公庁・団体', 'その他'
]));
