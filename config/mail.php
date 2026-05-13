<?php
// ============================================================
// CẤU HÌNH GMAIL SMTP
// ============================================================
// HƯỚNG DẪN TẠO APP PASSWORD:
// 1. Vào: https://myaccount.google.com/apppasswords
// 2. Đăng nhập Gmail: lipro.logistics@gmail.com
// 3. Chọn App: Mail | Device: Other | Đặt tên: Forwarder
// 4. Copy 16 ký tự vào MAIL_PASSWORD bên dưới
// ============================================================

define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_USERNAME',   'dungdaongocanh@gmail.com');
define('MAIL_PASSWORD',   'bbwb tkyy ypua yxgn'); // ← Thay bằng App Password 16 ký tự
define('MAIL_FROM',       'dungdaongocanh@gmail.com');
define('MAIL_FROM_NAME',  'LIPRO LOGISTICS CO.,LTD');
?>